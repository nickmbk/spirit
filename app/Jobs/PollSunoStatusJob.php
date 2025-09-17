<?php

namespace App\Jobs;

use App\Models\Meditation;
use App\Services\Api\Suno;
use App\Services\DatabaseLogger;
use Dflydev\DotAccessData\Data;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;

class PollSunoStatusJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    protected int $meditationId;
    protected string $meditationDate;
    protected string $tempVoicePath;
    protected string $taskId;
    protected int $attempts;

    public function __construct($meditationId, $meditationDate, $tempVoicePath, $taskId, $attempts = 0) {
        $this->meditationId = $meditationId;
        $this->meditationDate = $meditationDate;
        $this->tempVoicePath = $tempVoicePath;
        $this->taskId = $taskId;
        $this->attempts = $attempts;
    }

    public function backoff(): int|array
    {
        // 10s, 20s, 30s… (cap at 30s)
        return min(10 + $this->attempts * 10, 30);
    }

    public function handle(): void
    {
        /** @var Suno $suno */
        $suno = app(Suno::class);

        $details = $suno->getMusicDetails($this->taskId);
        $status  = data_get($details, 'data.status'); // e.g. PENDING|TEXT_SUCCESS|FIRST_SUCCESS|SUCCESS|...

        if ($status === 'SUCCESS') {
            $items = data_get($details, 'data.response.sunoData', []);

            // Pick the track you want (longest by default)
            $best = collect($items)
                ->filter(fn($t) => filled(data_get($t, 'audioUrl')))
                ->sortByDesc(fn($t) => (float) data_get($t, 'duration', 0))
                ->first();

            if (!$best) {
                return; // nothing to do
            }

            // Start WAV conversion (ideally pass both taskId and the specific audio/track id)
            $trackId   = data_get($best, 'id');
            $wavTaskId = app(\App\Services\Api\Suno::class)->requestWav($this->taskId, $trackId);

            // Poll WAV status until ready (short backoff loop)
            $tries = 0;
            $wavUrl = null;
            while ($tries < 10) {                     // up to ~3–4 mins total depending on your backoff
                sleep(min(10 + $tries * 5, 30));      // 10s,15s,20s... max 30s between checks
                $wav = app(\App\Services\Api\Suno::class)->getWavDetails($wavTaskId);

                $wavStatus = data_get($wav, 'data.status');
                if ($wavStatus === 'SUCCESS') {
                    // some payloads use snake_case, some camelCase — cover both:
                    $wavUrl = data_get($wav, 'data.response.audio_wav_url')
                        ?? data_get($wav, 'data.response.audioWavUrl');
                    break;
                }

                // Optional: bail early on explicit failure states if the API returns them
                if (in_array($wavStatus, ['FAILED','ERROR','CALLBACK_EXCEPTION'], true)) {
                    return;
                }

                $tries++;
            }

            if (!$wavUrl) {
                return; // couldn’t get the WAV URL this time
            }

            // Download the WAV BYTES (not saving the remote URL)
            $wavBytes = Http::timeout(300)->get($wavUrl)->throw()->body();

            // ⬇️ Your helper: this writes BYTES to storage/app/tmp/{filename}.wav
            $tempPath = $this->saveTempFile($wavBytes);

            MusicResponseJob::dispatch($this->meditationId, $this->meditationDate, $this->tempVoicePath, $tempPath);
            DatabaseLogger::info(self::class, 'Suno music generation completed', [
                'meditation_id' => $this->meditationId,
                'suno_task_id'  => $this->taskId,
                'wav_task_id'   => $wavTaskId,
                'wav_path'      => $tempPath,
            ], $this->job?->getJobId());
        }
    }

    private function saveTempFile(string $file): string
    {
        $fileName = 'meditation_music_' . $this->meditationId . '_' . $this->meditationDate . '.wav';

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
