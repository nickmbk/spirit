<?php

namespace App\Services;

use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Log;

class AudioMix
{
    /**
     * Mix voice over music with:
     * - voice delay (offsetMs)
     * - volume ratio (voice 0.85, music 0.15)
     * - loop music to cover voice + tail
     * - optional fade-out at the end
     *
     * @param  string  $voiceBinary      Raw bytes (prefer WAV from ElevenLabs)
     * @param  string  $musicBinary      Raw bytes (Suno MP3 usually)
     * @param  int     $offsetMs         Start voice after N ms (e.g. 4000)
     * @param  int     $tailMs           Extra music after voice ends (e.g. 4000)
     * @param  float   $voiceVol         0.0–2.0 (0.85 ≈ 85%)
     * @param  float   $musicVol         0.0–2.0 (0.15 ≈ 15%)
     * @param  bool    $fadeOut          Apply fade-out at end
     * @param  int     $fadeMs           Fade duration in ms
     * @param  string  $outFormat        'mp3' or 'wav'
     * @return array{binary:string,mime:string,seconds:float}
     */
    public function mixWithOffsetAndLoop(
        string $voiceBinary,
        string $musicBinary,
        int $offsetMs = 5000,     // start voice 5s after music
        int $tailMs = 5000,       // keep music 5s after voice ends
        float $voiceVol = 0.85,   // will also be reflected in weights
        float $musicVol = 0.15,   // "
        bool $fadeOut = true,
        int $fadeMs = 5000,       // 5s fade
        string $outFormat = 'mp3'
    ): array {
        $tmp = storage_path('app/tmp');
        if (!is_dir($tmp)) mkdir($tmp, 0775, true);

        $voiceIn = tempnam($tmp, 'voice_') . '.wav';
        $musicIn = tempnam($tmp, 'music_') . '.mp3';
        file_put_contents($voiceIn, $voiceBinary);
        file_put_contents($musicIn, $musicBinary);

        // Basic sanity check to avoid processing empty/invalid inputs
        $voiceSize = @filesize($voiceIn) ?: 0;
        if ($voiceSize <= 0) {
            Log::error('AudioMix: Empty voice input written to temp file.', [
                'voice_path' => $voiceIn,
            ]);
            $this->cleanup([$voiceIn, $musicIn]);
            throw new \RuntimeException('Voice input is empty — cannot proceed.');
        }

        // voice duration (seconds)
        $voiceSeconds = $this->probeDuration($voiceIn);
        $extraCleanup = [];
        if ($voiceSeconds <= 0) {
            Log::warning('AudioMix: ffprobe could not determine voice duration — attempting re-encode fallback.', [
                'voice_path' => $voiceIn,
                'voice_size' => $voiceSize,
            ]);

            // Try re-encoding the voice to a clean WAV to help ffprobe
            $reencoded = $this->reencodeToWav($voiceIn, $tmp);
            if ($reencoded) {
                $extraCleanup[] = $reencoded;
                $probeAgain = $this->probeDuration($reencoded);
                if ($probeAgain > 0) {
                    $voiceIn = $reencoded; // use the re-encoded file for the rest of the pipeline
                    $voiceSeconds = $probeAgain;
                    Log::info('AudioMix: duration recovered after re-encode.', [
                        'seconds' => $voiceSeconds,
                    ]);
                }
            }

            // If still unknown, optionally fall back to a configured default duration
            if ($voiceSeconds <= 0) {
                $fallbackSeconds = (float) (env('AUDIO_DURATION_FALLBACK', 0));
                if ($fallbackSeconds > 0) {
                    Log::warning('AudioMix: Using configured fallback voice duration.', [
                        'fallback_seconds' => $fallbackSeconds,
                    ]);
                    $voiceSeconds = $fallbackSeconds;
                } else {
                    $this->cleanup(array_merge([$voiceIn, $musicIn], $extraCleanup));
                    $hint = 'Ensure ffmpeg/ffprobe are installed and available on the server PATH. '
                          . 'You can also set AUDIO_DURATION_FALLBACK (seconds) to keep production running temporarily.';
                    throw new \RuntimeException('Could not determine voice duration. ' . $hint);
                }
            }
        }

        // total output length = offset + voice + tail
        $targetSeconds = ($offsetMs / 1000) + $voiceSeconds + ($tailMs / 1000);

        $outExt = $outFormat === 'wav' ? 'wav' : 'mp3';
        $out = tempnam($tmp, 'mix_') . ".$outExt";

        // Fade parameters
        $fadeSec = $fadeMs / 1000;
        $fadeStart = max(0, $targetSeconds - $fadeSec);

        // ---- filter graph ----
        // [0:a] is MUSIC (looped in input args), [1:a] is VOICE
        // - Delay voice by offsetMs
        // - (Optional) extra volume stages before amix (kept here, but weights already enforce balance)
        // - amix with explicit weights enforces 15%/85% balance, normalize=0 prevents auto-scaling
        // - Trim final mix to targetSeconds and (optionally) fade-out
        $filter = sprintf(
            "[1:a]adelay=%d|%d,volume=%.3f[v];" .
            "[0:a]volume=%.3f[m];" .
            "[m][v]amix=inputs=2:weights=%.3f %.3f:normalize=0:duration=longest:dropout_transition=3[mix];" .
            "[mix]atrim=0:%F,asetpts=PTS-STARTPTS%s[aout]",
            $offsetMs, $offsetMs,
            $voiceVol,
            $musicVol,
            $musicVol, $voiceVol,
            $targetSeconds,
            $fadeOut ? sprintf(",afade=t=out:st=%F:d=%F", $fadeStart, $fadeSec) : ""
        );

        $cmd = [
            'ffmpeg','-y',
            // Loop MUSIC forever so it covers the whole mix
            '-stream_loop','-1','-i',$musicIn,
            // Voice (no loop)
            '-i',$voiceIn,
            '-filter_complex', $filter,
            '-map','[aout]',
            '-ac','2','-ar','44100',
        ];

        if ($outExt === 'mp3') {
            array_push($cmd, '-c:a','libmp3lame','-b:a','192k', $out);
        } else {
            array_push($cmd, '-c:a','pcm_s16le', $out);
        }

        $proc = new \Symfony\Component\Process\Process($cmd);
        $proc->setTimeout(180);
        $proc->run();

        if (!$proc->isSuccessful() || !file_exists($out)) {
            $stderr = $proc->getErrorOutput();
            Log::error('AudioMix: ffmpeg mixing failed.', [ 'stderr' => $stderr ]);
            $this->cleanup(array_merge([$voiceIn, $musicIn, $out], $extraCleanup));
            throw new \RuntimeException("FFmpeg failed: {$stderr}");
        }

        $binary = file_get_contents($out);
        $mime   = $outExt === 'mp3' ? 'audio/mpeg' : 'audio/wav';

    $this->cleanup(array_merge([$voiceIn, $musicIn, $out], $extraCleanup));

        return [
            'binary'  => $binary,
            'mime'    => $mime,
            'seconds' => $targetSeconds,
        ];
    }

    protected function probeDuration(string $path): float
    {
        try {
            $p = new Process([
                'ffprobe','-v','error','-show_entries','format=duration',
                '-of','default=noprint_wrappers=1:nokey=1',$path
            ]);
            $p->setTimeout(15);
            $p->run();
        } catch (\Throwable $e) {
            Log::error('AudioMix: ffprobe execution failed.', [
                'message' => $e->getMessage(),
            ]);
            return 0.0;
        }
        if (!$p->isSuccessful()) {
            Log::warning('AudioMix: ffprobe returned non-success.', [
                'stderr' => $p->getErrorOutput(),
            ]);
            return 0.0;
        }
        return (float) trim($p->getOutput());
    }

    protected function cleanup(array $paths): void
    {
        foreach ($paths as $p) {
            if ($p && file_exists($p)) @unlink($p);
        }
    }

    /**
     * Try to re-encode any input audio to a clean WAV the pipeline can consume.
     * Returns the path to the re-encoded file on success, or null on failure.
     */
    protected function reencodeToWav(string $inPath, string $tmpDir): ?string
    {
        $out = tempnam($tmpDir, 'revoice_') . '.wav';
        try {
            $proc = new Process([
                'ffmpeg','-y','-i',$inPath,
                '-ac','2','-ar','44100','-c:a','pcm_s16le',
                $out
            ]);
            $proc->setTimeout(60);
            $proc->run();
            if ($proc->isSuccessful() && file_exists($out) && (@filesize($out) ?: 0) > 0) {
                return $out;
            }
            Log::warning('AudioMix: re-encode to WAV failed.', [
                'stderr' => $proc->getErrorOutput(),
            ]);
        } catch (\Throwable $e) {
            Log::error('AudioMix: re-encode threw exception.', [
                'message' => $e->getMessage(),
            ]);
        }
        // Clean up a possibly empty output file
        if (file_exists($out) && ((@filesize($out) ?: 0) === 0)) {
            @unlink($out);
        }
        return null;
    }
}
