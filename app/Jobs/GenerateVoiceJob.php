<?php

namespace App\Jobs;

use App\Models\Meditation;
use App\Services\Api\ElevenLabs;
use App\Services\Api\GoogleDrive;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\DatabaseLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateVoiceJob implements ShouldQueue
{
    use Queueable;

    protected $meditationId;
    protected $meditationDate;
    protected $script;

    /**
     * Create a new job instance.
     */
    public function __construct($meditationId, $meditationDate, $script)
    {
        $this->meditationId = $meditationId;
        $this->meditationDate = $meditationDate;
        $this->script = $script;
    }

    public int $timeout = 900;       // 15 min for long tasks (FFmpeg)
    public bool $failOnTimeout = true;

    public int $tries = 3;           // or higher for pollers
    public function backoff(): array  // exponential backoff for retries
    {
        return [10, 30, 60];
    }

    /**
     * Execute the job.
     */
    public function handle(ElevenLabs $elevenLabs): void
    {
        try {
            // send the script to eleven labs to generate the voice audio
            $voiceFile = $elevenLabs->createVoice($this->script);
            // wrap the returned audio in a wav header for saving as wav
            $voiceFile = $this->wrapPcm16leToWav($voiceFile, 16000, 1);

            // save the file temporarily to disk for creation process
            $tempVoicePath = $this->saveTempFile($voiceFile);

            Log::info('2.1 voice generated', ['audioPath' => $tempVoicePath]);
            DatabaseLogger::info(self::class, 'Creation of ElevenLabs Voice Service has started', [
                'meditation_id' => $this->meditationId,
                'meditation_date' => $this->meditationDate
            ], $this->job?->getJobId());

            // upload to google drive
            $googleDrive = new GoogleDrive();
            $googleFileName = 'meditation_voice_' . $this->meditationId . '_' . $this->meditationDate . '.wav';
            $uploadPath = $googleDrive->uploadPath($tempVoicePath, $googleFileName, 'audio/wav', 'test');
            $googleDrive->makeAnyoneReader($uploadPath);
            $voiceDrivePath = $googleDrive->publicDownloadLink($uploadPath);

            // save google drive upload location to meditation record
            $meditation = Meditation::find($this->meditationId);
            $meditation->voice_url = $voiceDrivePath;
            $meditation->save();

            Log::info('2.2 voice saved to google and db', ['audioPath' => $voiceDrivePath]);
            DatabaseLogger::info(self::class, 'Eleven labs save to google and db', [
                'meditation_id' => $this->meditationId,
                'meditation_date' => $this->meditationDate
            ], $this->job?->getJobId());
            
            // pass to next job to create music
            GenerateMusicJob::dispatch($this->meditationId, $this->meditationDate, $tempVoicePath);
        } catch(Exception $e) {
            Log::error('Error generating voice: ' . $e->getMessage(), ['exception' => $e]);
            DatabaseLogger::error(self::class, 'Error generating voice: ' . $e->getMessage(), [
                'meditation_id' => $this->meditationId,
                'meditation_date' => $this->meditationDate,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], $this->job?->getJobId());
        }
    }

    private function saveTempFile(string $file): string
    {
        $fileName = 'meditation_voice_' . $this->meditationId . '_' . $this->meditationDate . '.wav';

        $absPath = storage_path('app/tmp/' . $fileName);

        // make sure tmp directory exists
        if (!is_dir(dirname($absPath))) {
            mkdir(dirname($absPath), 0775, true);
        }

        // save the bytes to disk
        $saved = file_put_contents($absPath, $file);

        if (!$saved) {
            throw new \Exception('Failed to save temporary audio file: ' . $absPath);
        }

        return $absPath;
    }

    private function wrapPcm16leToWav(string $pcm, int $sampleRate = 16000, int $channels = 1): string
    {
        $bitsPerSample = 16; // ElevenLabs PCM is 16-bit little-endian
        $byteRate      = $sampleRate * $channels * ($bitsPerSample / 8);
        $blockAlign    = $channels * ($bitsPerSample / 8);
        $dataSize      = strlen($pcm);
        $riffSize      = 36 + $dataSize;

        return 'RIFF'
            . pack('V', $riffSize)
            . 'WAVEfmt '
            . pack('V', 16)                 // fmt chunk size
            . pack('v', 1)                  // PCM format
            . pack('v', $channels)
            . pack('V', $sampleRate)
            . pack('V', $byteRate)
            . pack('v', $blockAlign)
            . pack('v', $bitsPerSample)
            . 'data'
            . pack('V', $dataSize)
            . $pcm;
    }

    private function loadFile(string $path, string $method = 'get'): string
    {
        $rel = 'meditation_voice_66_18092025.wav';
        Log::info('local check', [
            'exists' => Storage::disk('temp')->exists($rel),
            'size'   => Storage::disk('temp')->exists($rel) ? Storage::disk('temp')->size($rel) : null,
            'abs'    => Storage::disk('temp')->path($rel),
        ]);
        if ($method === 'path') {
            return Storage::disk('temp')->path($path);
        } else {
            return Storage::disk('temp')->get($path);
        }
    }
}
