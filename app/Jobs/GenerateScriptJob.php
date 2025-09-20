<?php

namespace App\Jobs;

use App\Models\Meditation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\Api\OpenAi;
use Illuminate\Support\Facades\Log;
use App\Services\DatabaseLogger;
use Exception;

class GenerateScriptJob implements ShouldQueue
{
    use Queueable;

    protected $meditationId;
    protected $meditationDate;
    protected $script;

    /**
     * Create a new job instance.
     */
    public function __construct($meditationId, $meditationDate)
    {
        $this->meditationId = $meditationId;
        $this->meditationDate = $meditationDate;
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
    public function handle(OpenAi $openAi): void
    {
        try {
            // call the api to generate the script
            $script = $openAi->createScript($this->meditationId);

            // log any issues to the database
            DatabaseLogger::info(self::class, 'Creation of OpenAI API Service has started', [
                'meditation_id' => $this->meditationId,
                'meditation_date' => $this->meditationDate
            ], $this->job?->getJobId());

            // save the script to the meditation record
            $meditation = Meditation::find($this->meditationId);
            $meditation->script_text = $script;// TODO: save in google drive as text file
            $meditation->save();

            // pass the script to the next job to create the voice audio file
            GenerateVoiceJob::dispatch($this->meditationId, $this->meditationDate, $script);
        } catch (Exception $e) {
            Log::error('Error generating script: ' . $e->getMessage(), ['exception' => $e]);
            DatabaseLogger::error(self::class, 'Error generating script: ' . $e->getMessage(), [
                'meditation_id' => $this->meditationId,
                'meditation_date' => $this->meditationDate,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], $this->job?->getJobId());
        }
    }
}
