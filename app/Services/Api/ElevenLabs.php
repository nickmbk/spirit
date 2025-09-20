<?php

namespace App\Services\Api;

use Illuminate\Http\Client\Response;

class ElevenLabs extends BaseApi
{
    protected function baseUrl(): string
    {
        return config('services.elevenlabs.base_url');
    }

    protected function token(): ?string
    {
        return '';
    }

    protected function defaultHeaders(): array
    {
        // Default JSON for any metadata endpoints.
        return parent::defaultHeaders() + [
            'xi-api-key' => config('services.elevenlabs.api_key'),
            'Accept'     => 'audio/wav',
        ];
    }

    protected function voiceId(): string
    {
        return (string) config('services.elevenlabs.voice_id');
    }

    public function createVoice(string $text): Response
    {
        $body = [
            'text'     => $text,
            'model_id' => 'eleven_multilingual_v2'
        ];

        return $this->post("/text-to-speech/{$this->voiceId()}?output_format=pcm_16000", $body);
    }
}
