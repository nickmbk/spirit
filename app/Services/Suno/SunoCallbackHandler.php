<?php

namespace App\Services\Suno;

use App\Jobs\MusicResponseJob;
use App\Models\Meditation;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;

class SunoCallbackHandler
{
    public function __construct(private Cache $cache) {}

    /**
     * Handle Suno's callback payload.
     * Expects shape: { code, data: { callbackType, task_id|taskId, data: [...] } }
     */
    public function handle(array $payload): void
    {
        $taskId       = data_get($payload, 'data.task_id') ?? data_get($payload, 'data.taskId');
        if (!$taskId) {
            Log::warning('Suno webhook missing taskId', ['payload' => $payload]);
            return;
        }

        $code         = (int) data_get($payload, 'code');
        $callbackType = data_get($payload, 'data.callbackType'); // text|first|complete|error
        $items        = data_get($payload, 'data.data', []);     // tracks array (snake_case keys)

        $this->cache->lock("suno:cb:{$taskId}", 10)->block(5, function () use ($taskId, $code, $callbackType, $items) {
            $meditation = Meditation::where('music_task_id', $taskId)->first();
            if (!$meditation) {
                Log::warning('Suno taskId not linked to Meditation', compact('taskId'));
                return;
            }

            if ($code !== 200) {
                $meditation->update(['music_status' => 'failed']);
                return;
            }

            // Early callbacks can have empty audio_url; only proceed on 'complete' with audio
            $hasAudio = collect($items)->contains(fn($t) => filled(data_get($t, 'audio_url')));

            if ($callbackType !== 'complete' || !$hasAudio) {
                $meditation->update(['music_status' => 'in_progress']);
                return;
            }

            // Offload downloads & next steps
            MusicResponseJob::dispatch($meditation->id, $items);
            $meditation->update(['music_status' => 'complete']);
        });
    }
}
