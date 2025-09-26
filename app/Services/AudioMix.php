<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class AudioMix
{
    private string $ffmpegBin;
    private string $ffprobeBin;
    private string $pathEnv;

    public function __construct()
    {
        // Use absolute paths from env if set (e.g. /var/www/bin/ffmpeg), otherwise fall back to command names.
        $this->ffmpegBin  = env('FFMPEG_BIN',  'ffmpeg');
        $this->ffprobeBin = env('FFPROBE_BIN', 'ffprobe');

        // Make sure spawned processes get a PATH that includes our bin dir.
        // /usr/local/bin is a common place we symlinked to; /var/www/bin is where we put the actual binaries.
        $this->pathEnv = '/usr/local/bin:/var/www/bin:' . (getenv('PATH') ?: '');
    }

    /**
     * Mix voice over music with:
     * - voice delay (offsetMs)
     * - volume ratio (voice 0.85, music 0.15)
     * - loop music to cover voice + tail
     * - optional fade-out at the end
     *
     * @return array{binary:string,mime:string,seconds:float}
     */
    public function mixWithOffsetAndLoop(
        string $voiceBinary,
        string $musicBinary,
        int $offsetMs = 5000,
        int $tailMs = 5000,
        float $voiceVol = 0.85,
        float $musicVol = 0.15,
        bool $fadeOut = true,
        int $fadeMs = 5000,
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

        // Probe voice duration
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
                    $voiceIn = $reencoded;
                    $voiceSeconds = $probeAgain;
                    Log::info('AudioMix: duration recovered after re-encode.', [
                        'seconds' => $voiceSeconds,
                    ]);
                }
            }

            // Optional fallback duration so the job can continue
            if ($voiceSeconds <= 0) {
                $fallbackSeconds = (float) env('AUDIO_DURATION_FALLBACK', 0);
                if ($fallbackSeconds > 0) {
                    Log::warning('AudioMix: Using configured fallback voice duration.', [
                        'fallback_seconds' => $fallbackSeconds,
                    ]);
                    $voiceSeconds = $fallbackSeconds;
                } else {
                    $this->cleanup(array_merge([$voiceIn, $musicIn], $extraCleanup));
                    $hint = 'Ensure ffmpeg/ffprobe paths are set (FFMPEG_BIN / FFPROBE_BIN) or on PATH.';
                    throw new \RuntimeException('Could not determine voice duration. ' . $hint);
                }
            }
        }

        // total output length = offset + voice + tail
        $targetSeconds = ($offsetMs / 1000) + $voiceSeconds + ($tailMs / 1000);

        $outExt = $outFormat === 'wav' ? 'wav' : 'mp3';
        $out = tempnam($tmp, 'mix_') . ".$outExt";

        // Fade
        $fadeSec   = $fadeMs / 1000;
        $fadeStart = max(0, $targetSeconds - $fadeSec);

        // Build filter graph
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

        // Use the configured ffmpeg binary
        $cmd = [
            $this->ffmpegBin, '-y',
            '-stream_loop','-1','-i',$musicIn, // loop music
            '-i',$voiceIn,                     // voice
            '-filter_complex', $filter,
            '-map','[aout]',
            '-ac','2','-ar','44100',
        ];

        if ($outExt === 'mp3') {
            array_push($cmd, '-c:a','libmp3lame','-b:a','192k', $out);
        } else {
            array_push($cmd, '-c:a','pcm_s16le', $out);
        }

        $proc = new Process($cmd);
        $proc->setEnv(['PATH' => $this->pathEnv]); // make sure our bin dir is visible
        $proc->setTimeout(180);
        $proc->run();

        if (!$proc->isSuccessful() || !file_exists($out)) {
            $stderr = $proc->getErrorOutput();
            Log::error('AudioMix: ffmpeg mixing failed.', [
                'stderr' => $stderr,
                'ffmpeg' => $this->ffmpegBin,
                'voice'  => $voiceIn,
                'music'  => $musicIn,
                'out'    => $out,
            ]);
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
                $this->ffprobeBin, '-v','error',
                '-show_entries','format=duration',
                '-of','default=noprint_wrappers=1:nokey=1',
                $path,
            ]);
            $p->setEnv(['PATH' => $this->pathEnv]);
            $p->setTimeout(15);
            $p->run();
        } catch (\Throwable $e) {
            Log::error('AudioMix: ffprobe execution failed.', [
                'message' => $e->getMessage(),
                'ffprobe' => $this->ffprobeBin,
                'path'    => $path,
            ]);
            return 0.0;
        }

        if (!$p->isSuccessful()) {
            Log::warning('AudioMix: ffprobe returned non-success.', [
                'stderr'  => $p->getErrorOutput(),
                'ffprobe' => $this->ffprobeBin,
                'path'    => $path,
            ]);
            return 0.0;
        }

        return (float) trim($p->getOutput());
    }

    protected function reencodeToWav(string $inPath, string $tmpDir): ?string
    {
        $out = tempnam($tmpDir, 'revoice_') . '.wav';

        try {
            $proc = new Process([
                $this->ffmpegBin, '-y','-i',$inPath,
                '-ac','2','-ar','44100','-c:a','pcm_s16le',
                $out,
            ]);
            $proc->setEnv(['PATH' => $this->pathEnv]);
            $proc->setTimeout(60);
            $proc->run();

            if ($proc->isSuccessful() && file_exists($out) && ((@filesize($out) ?: 0) > 0)) {
                return $out;
            }

            Log::warning('AudioMix: re-encode to WAV failed.', [
                'stderr' => $proc->getErrorOutput(),
                'ffmpeg' => $this->ffmpegBin,
                'in'     => $inPath,
                'out'    => $out,
            ]);
        } catch (\Throwable $e) {
            Log::error('AudioMix: re-encode threw exception.', [
                'message' => $e->getMessage(),
                'ffmpeg'  => $this->ffmpegBin,
            ]);
        }

        if (file_exists($out) && ((@filesize($out) ?: 0) === 0)) {
            @unlink($out);
        }
        return null;
    }

    protected function cleanup(array $paths): void
    {
        foreach ($paths as $p) {
            if ($p && file_exists($p)) @unlink($p);
        }
    }
}
