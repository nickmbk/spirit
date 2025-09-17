<?php

namespace App\Services\Api;

class ElevenLabs extends BaseApi
{
    protected function baseUrl(): string
    {
        return config('services.elevenlabs.base_url');
    }

    protected function token(): ?string
    {
        return config('services.elevenlabs.key');
    }

    protected function defaultHeaders(): array
    {
        // Default JSON for any metadata endpoints.
        return parent::defaultHeaders() + [
            'xi-api-key' => $this->token(),
        ];
    }

    protected function voiceId(): string
    {
        return (string) config('services.elevenlabs.voice_id');
    }

    public function createVoice(string $text): string
    {
        $body = [
            'text'     => $text,
            'model_id' => 'eleven_multilingual_v2'
        ];

        return $this->post("/text-to-speech/{$this->voiceId()}?output_format=pcm_48000", $body);
    }
}



// <?php

// namespace App\Services\Api;

// use Illuminate\Http\Client\Response;
// use App\Services\Api\BaseApi;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Storage;

// class ElevenLabs extends BaseApi
// {
//     public function __construct()
//     {
//         parent::__construct(
//             baseUrl: config('services.elevenlabs.base_url'),
//             headers: [
//                 'Accept' => 'audio/wav',
//                 'Content-Type' => 'application/json',
//             ],
//             token: config('services.elevenlabs.api_key')
//         );
//     }

//     public function generateScriptAudio(string $text, string $model = 'eleven_multilingual_v2'): Response
//     {
//         return $this->post("/text-to-speech/21m00Tcm4TlvDq8ikWAM", [// TODO: Nathans voice ID to go in here
//             'model_id' => $model,
//             'text' => $text,
//             'voice_settings' => [
//                 'stability' => 0.7,
//                 'similarity_boost' => 0.9,
//             ],
//         ]);
//     }

//     public function generateFakeAudio(): string
//     {
//         return 'test-audio/test-script.mp3';
//     }

//     public function getFakeAudio(): ?string
//     {
//         return Storage::disk('public')->get($this->generateFakeAudio());
//     }
// }