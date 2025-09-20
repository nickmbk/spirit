<?php

namespace App\Jobs;

use App\Models\Meditation;
use App\Services\Api\ElevenLabs;
use App\Services\Api\GoogleDrive;
use App\Services\Api\Suno;
use App\Services\DatabaseLogger;
use Dflydev\DotAccessData\Data;
use Google\Service\BigLakeService\Database;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateMusicJob implements ShouldQueue
{
    use Queueable;

    protected $meditationId;
    protected $meditationDate;
    protected $tempVoicePath;

    public function __construct($meditationId, $meditationDate, $tempVoicePath)
    {
        $this->meditationId = $meditationId;
        $this->meditationDate = $meditationDate;
        $this->tempVoicePath = $tempVoicePath;
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
    public function handle(Suno $suno): void
    {
        // Use suno api to create suno track
        $sunoTaskId = $suno->createMusic($this->meditationId);

        Log::info('3.1 suno created', ['suno' => 'suno']);
        DatabaseLogger::info(self::class, 'Creation of Suno Music Service has started', [
            'meditation_id' => $this->meditationId,
            'meditation_date' => $this->meditationDate,
            'suno_task_id' => $sunoTaskId
        ], $this->job?->getJobId());

        // save the suno task id to the meditation record
        $meditation = Meditation::find($this->meditationId);
        $meditation->music_task_id = $sunoTaskId;
        $meditation->save();

        Log::info('3.2 suno task id saved to meditation db', ['meditation' => $sunoTaskId]);
        DatabaseLogger::info(self::class, 'Suno task ID saved to meditation', [
            'meditation_id' => $this->meditationId,
            'meditation_date' => $this->meditationDate,
            'suno_task_id' => $sunoTaskId
        ], $this->job?->getJobId());

        // Poll for status - replace when going to staging
        PollSunoStatusJob::dispatch($this->meditationId, $this->meditationDate, $this->tempVoicePath, $sunoTaskId);
    }
}
