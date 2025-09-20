<?php

namespace App\Jobs;

use App\Models\Meditation;
use App\Services\Api\Suno;
use App\Services\DatabaseLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\InteractsWithQueue;

class PollSunoStatusJob implements ShouldQueue
{
    use Dispatchable, Queueable, InteractsWithQueue;

    protected int $meditationId;
    protected string $meditationDate;
    protected string $tempVoicePath;
    protected string $taskId;

        public function __construct($meditationId, $meditationDate, $tempVoicePath, $taskId)
    {
        $this->meditationId  = $meditationId;
        $this->meditationDate = $meditationDate;
        $this->tempVoicePath = $tempVoicePath;
        $this->taskId        = $taskId;
    }

    public int $timeout = 900;       // 15 min for long tasks (FFmpeg)
    public bool $failOnTimeout = true;

    public int $tries = 50;           // or higher for pollers
    public function backoff(): array  // exponential backoff for retries
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        $jobId = (property_exists($this, 'job') && $this->job) ? $this->job->getJobId() : null;

        Log::info('[PollSuno] handle:start', [
            'job_id'        => $jobId,
            'meditation_id' => $this->meditationId,
            'task_id'       => $this->taskId,
            'job_attempts'  => method_exists($this, 'attempts') ? $this->attempts() : null,
        ]);

        try {
            /** @var Suno $suno */
            $suno = app(Suno::class);

            // ---- 1) Get task status ----
            $details = $suno->getMusicDetails($this->taskId);
            $status  = data_get($details, 'data.status');
            $itemCnt = count((array) data_get($details, 'data.response.sunoData', []));
            Log::info('[PollSuno] task status', [
                'job_id'        => $jobId,
                'task_id'       => $this->taskId,
                'status'        => $status,
                'items_count'   => $itemCnt,
                'keys'          => is_array($details) ? array_slice(array_keys($details), 0, 6) : gettype($details),
            ]);

            if ($status !== 'SUCCESS') {
            $n = method_exists($this, 'attempts') ? max(1, (int) $this->attempts()) : 1;
            $delay = min(10 + ($n - 1) * 10, 30); // 10s, 20s, 30s cap

            Log::info('[PollSuno] not ready, releasing back to queue', [
                'task_id' => $this->taskId,
                'attempt' => $n,
                'delay_s' => $delay,
            ]);

            $this->release($delay);   // 👈 re-queue THIS job instance
            return;
        }

            // ---- 2) Choose best track ----
            $items = data_get($details, 'data.response.sunoData', []);
            $best  = collect($items)
                ->filter(fn ($t) => filled(data_get($t, 'audioUrl')))
                ->sortByDesc(fn ($t) => (float) data_get($t, 'duration', 0))
                ->first();

            if (!$best) {
                Log::warning('[PollSuno] SUCCESS but no track with audioUrl', [
                    'job_id'  => $jobId,
                    'task_id' => $this->taskId,
                ]);
                return;
            }

            $trackId   = data_get($best, 'id');
            $audioUrl  = data_get($best, 'audioUrl');
            $duration  = (float) data_get($best, 'duration', 0.0);

            Log::info('[PollSuno] picked track', [
                'job_id'   => $jobId,
                'track_id' => $trackId,
                'duration' => $duration,
                'has_mp3'  => (bool) $audioUrl,
            ]);

            // ---- 3) Request WAV for that track ----
            $wavTaskId = app(Suno::class)->requestWav($this->taskId, $trackId);
            Log::info('[PollSuno] requested WAV', [
                'job_id'     => $jobId,
                'task_id'    => $this->taskId,
                'track_id'   => $trackId,
                'wav_taskId' => $wavTaskId,
            ]);

            // ---- 4) Poll for WAV URL ----
            $tries  = 0;
            $wavUrl = null;

            while ($tries < 10) {
                $sleep = min(10 + $tries * 5, 30);
                Log::info('[PollSuno] WAV poll: sleeping', [
                    'job_id'   => $jobId,
                    'try'      => $tries + 1,
                    'sleep_s'  => $sleep,
                ]);
                sleep($sleep);

                $wav = app(Suno::class)->getWavDetails($wavTaskId);
                $wavStatus = data_get($wav, 'data.status');
                $wavUrl = data_get($wav, 'data.response.audio_wav_url')
                    ?? data_get($wav, 'data.response.audioWavUrl');

                Log::info('[PollSuno] WAV poll: status', [
                    'job_id'    => $jobId,
                    'try'       => $tries + 1,
                    'wavStatus' => $wavStatus,
                    'has_url'   => (bool) $wavUrl,
                ]);

                if ($wavStatus === 'SUCCESS' && $wavUrl) {
                    break;
                }

                if (in_array($wavStatus, ['FAILED', 'ERROR', 'CALLBACK_EXCEPTION'], true)) {
                    Log::error('[PollSuno] WAV failed', [
                        'job_id'    => $jobId,
                        'wavStatus' => $wavStatus,
                        'wav_resp_keys' => is_array($wav) ? array_slice(array_keys($wav), 0, 6) : gettype($wav),
                    ]);
                    return;
                }

                $tries++;
            }

            if (!$wavUrl) {
                Log::warning('[PollSuno] WAV URL not ready after retries, exiting', [
                    'job_id'   => $jobId,
                    'wav_task' => $wavTaskId,
                    'tries'    => $tries,
                ]);
                return;
            }

            // ---- 5) Download WAV bytes ----
            Log::info('[PollSuno] downloading WAV', [
                'job_id' => $jobId,
                'url'    => $wavUrl,
            ]);

            $wavResp  = Http::timeout(300)->get($wavUrl);
            $wavResp->throw();
            $wavBytes = $wavResp->body();

            $byteLen = strlen($wavBytes ?? '');
            Log::info('[PollSuno] WAV downloaded', [
                'job_id'  => $jobId,
                'bytes'   => $byteLen,
                'riff'    => substr($wavBytes, 0, 4) === 'RIFF',
            ]);

            // ---- 6) Save to temp ----
            $tempMusicPath = $this->saveTempFile($wavBytes);
            Log::info('[PollSuno] WAV saved to temp', [
                'job_id'     => $jobId,
                'tempPath'   => $tempMusicPath,
                'exists'     => file_exists($tempMusicPath),
                'size'       => @filesize($tempMusicPath),
            ]);

            // ---- 7) Kick the next job ----
            MusicResponseJob::dispatch($this->meditationId, $this->meditationDate, $this->tempVoicePath, $tempMusicPath);

            DatabaseLogger::info(self::class, 'Suno music generation completed', [
                'meditation_id' => $this->meditationId,
                'suno_task_id'  => $this->taskId,
                'wav_task_id'   => $wavTaskId,
                'wav_path'      => $tempMusicPath,
            ], $jobId);

            Log::info('[PollSuno] handle:done', [
                'job_id'        => $jobId,
                'meditation_id' => $this->meditationId,
            ]);
        } catch (\Throwable $e) {
            Log::error('[PollSuno] exception', [
                'job_id'   => $jobId,
                'message'  => $e->getMessage(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
            ]);

            DatabaseLogger::error(self::class, $e->getMessage(), [
                'meditation_id' => $this->meditationId,
                'task_id'       => $this->taskId,
                'trace'         => $e->getTraceAsString(),
            ], $jobId);

            // Optional: rethrow so the job is marked failed and backoff applies
            throw $e;
        }
    }

    private function saveTempFile(string $file): string
    {
        $fileName = 'meditation_music_' . $this->meditationId . '_' . $this->meditationDate . '.wav';
        
        $absPath  = storage_path('app/tmp/' . $fileName);

        if (!is_dir(dirname($absPath))) {
            mkdir(dirname($absPath), 0775, true);
        }

        $saved = file_put_contents($absPath, $file);
        Log::info('[PollSuno] saveTempFile()', [
            'path'   => $absPath,
            'saved'  => $saved !== false,
            'bytes'  => is_int($saved) ? $saved : null,
        ]);

        if ($saved === false) {
            throw new \Exception('Failed to save temporary audio file: ' . $absPath);
        }

        return $absPath;
    }
}
