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

    /**
     * Execute the job.
     */
    public function handle(ElevenLabs $elevenLabs): void
    {
        try {
            $voice = $elevenLabs->createVoice($this->script);

            // save the file temporarily to disk for creation process
            $tempPath = $this->saveTempFile($voice);

            Log::info('2.1 voice generated', ['audioPath' => $tempPath]);
            DatabaseLogger::info(self::class, 'Creation of ElevenLabs Voice Service has started', [
                'meditation_id' => $this->meditationId,
                'meditation_date' => $this->meditationDate
            ], $this->job?->getJobId());

            // $audio = $elevenLabs->generateScriptAudio($this->script)->body();
            // $audioPath = $elevenLabs->generateFakeAudio();
            // $audio = $elevenLabs->getFakeAudio();

            // upload to google drive
            $googleDrive = new GoogleDrive();
            $googleFileName = 'meditation_voice_' . $this->meditationId . '_' . $this->meditationDate . '.wav';
            $uploadPath = $googleDrive->uploadPath($voice, $googleFileName, 'audio/wav', 'test');
            $googleDrive->makeAnyoneReader($uploadPath);
            $voicePath = $googleDrive->publicDownloadLink($uploadPath);

            // save google drive upload location to meditation record
            $meditation = Meditation::find($this->meditationId);
            $meditation->voice_url = $voicePath;
            $meditation->save();

            Log::info('2.2 voice saved to google and db', ['audioPath' => $voicePath]);
            DatabaseLogger::info(self::class, 'Eleven labs save to google and db', [
                'meditation_id' => $this->meditationId,
                'meditation_date' => $this->meditationDate
            ], $this->job?->getJobId());
            
            GenerateMusicJob::dispatch($this->meditationId, $this->meditationDate, $tempPath);
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
}
