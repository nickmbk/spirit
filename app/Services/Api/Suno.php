<?php

namespace App\Services\Api;

use App\Models\Meditation;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use App\Services\Api\BaseApi;

class Suno extends BaseApi
{
    protected function baseUrl(): string
    {
        return config('services.suno.base_url');
    }

    protected function token(): ?string
    {
        return config('services.suno.api_key');
    }

    protected function callBackUrl(): string
    {
        return config('services.suno.callback_url');
    }

    protected function defaultHeaders(): array
    {
        return parent::defaultHeaders() + [
            'Authorization' => 'Bearer ' . $this->token(),
            'Accept'        => 'application/json',
        ];
    }

    public function createMusic(int $meditationId): string
    {
        $meditation = Meditation::find($meditationId);
        $body = [
            'style' => 'Meditation, Ambient, ' . $meditation->style,
            'title' => 'Meditation Music for ' . $meditationId,
            'customMode' => true,
            'instrumental' => true,
            'model' => 'V3_5',
            'negativeTags' => 'Heavy Metal, Upbeat Drums',
            'styleWeight' => 0.65,
            'weirdnessConstraint' => 0.65,
            'audioWeight' => 0.65,
            'callBackUrl' => $this->callBackUrl()
        ];
        
        $taskId =  $this->post('/generate', $body)->json('data.taskId');
        $meditation->music_task_id = $taskId;
        $meditation->save();
        return $taskId;
    }

    public function getMusicDetails(string $taskId): array
    {
        // Suno status endpoint; returns camelCase keys
        return $this->get('/generate/record-info', ['taskId' => $taskId])->json();
    }

    // Start a WAV conversion (pass taskId AND the specific track id if you can)
    public function requestWav(string $taskId, ?string $audioId = null, ?string $callbackUrl = null): string
    {
        $body = array_filter([
            'taskId'      => $taskId,
            'audioId'     => $audioId, // track id from the generation payload (recommended)
            'callBackUrl' => $callbackUrl, // omit while you're polling
        ]);

        $res = $this->post('/wav/generate', $body)->json();
        $wavTaskId = data_get($res, 'data.taskId');
        if (!$wavTaskId) {
            throw new \RuntimeException('WAV conversion taskId not returned.');
        }
        return $wavTaskId;
    }

    // Poll WAV conversion status and get the download URL when ready
    public function getWavDetails(string $wavTaskId): array
    {
        return $this->get('/wav/record-info', ['taskId' => $wavTaskId])->json();
    }
}

// <?php

// namespace App\Services\Api;

// use Illuminate\Http\Client\Response;
// use Illuminate\Support\Facades\Http;
// use App\Services\Api\BaseApi;

// class Suno extends BaseApi
// {
//     public function __construct()
//     {
//         parent::__construct(
//             baseUrl: config('services.suno.base_url'),
//             headers: [
//                 'Authorization' => 'Bearer ' . config('services.suno.api_key'),
//                 'Content-Type'  => 'application/json',
//                 'Accept'        => 'application/json',
//             ]
//         );
//     }

//     /**
//      * Create a music task.
//      * Returns something like: ["data" => ["taskId" => "xxxx"]]
//      */
//     public function createTrack(string $prompt, bool $instrumental = true, string $model = 'sonic-v3-5'): Response
//     {
//         // many providers accept custom params like title/genre/length/instrumental/model.
//         return $this->post('/api/generate', [
//             'gpt_description_prompt' => $prompt,
//             'custom_mode'            => false,
//             'instrumental'           => $instrumental,
//             'mv'                     => $model, // model/version key used by several gateways
//         ]);
//     }

//     /**
//      * Fetch task status / results.
//      * Some APIs use /api/get?id=... or /api/get?ids=...
//      */
//     public function getTrack(string $taskId): Response
//     {
//         return $this->get('/api/get', ['id' => $taskId]);
//     }

//     public function findTrack($trackId)
//     {
//         // set up the url for the track
//         $trackUrl = null;
//         // set the poll attempts to 0
//         $pollAttempts = 0;
//         // set the intervals to check for the track being ready
//         $sleepSecs = [5, 8, 12, 15, 20, 25, 30, 35, 40, 40, 40];

//         while ($pollAttempts < count($sleepSecs)) {
//             // add 1 to the poll count
//             $pollAttempts++;

//             // check the status of the generation of the music track
//             $status = $this->getTrack($trackId)->json();

//             $tracks = data_get($status, 'data.songs', data_get($status, 'data', []));

//             foreach ($tracks as $track) {
//                  $candidate = $track['audio_url'] ?? $track['audio_url_mp3'] ?? null;
//                 if ($candidate) {
//                     $trackUrl = $candidate;
//                     break 2;
//                 }
//             }

//             $trackResp = Http::timeout(60)->get($trackUrl);
//             $trackResp->throw();
//             $binary = $trackResp->body();
//         }
//     }

//     public function generateFakeAudio(): string
//     {
//         return 'test-audio/test-background.mp3';
//     }
// }
