<?php

namespace App\Services\Api;

use App\Models\Meditation;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenAi extends BaseApi
{
    protected function baseUrl(): string
    {
        return config('services.openai.base_url');
    }

    protected function token(): ?string
    {
        return config('services.openai.api_key');
    }

    protected function defaultHeaders(): array
    {
        return parent::defaultHeaders() + [
            'Authorization' => 'Bearer ' . $this->token(),
        ];
    }

    public function createScript(int $meditationId, string $model = 'gpt-5'): string
    {
        $prompt = $this->buildPrompt($meditationId);

        Log::info('🧘 2.1 generating meditation script', [
            'meditationId' => $meditationId,
            'model'        => $model,
        ]);

        // First attempt
        $body = [
            'model'             => $model,
            'input'             => $prompt,
        ];

        $payload = $this->awaitResponse($body);
        $script  = $this->extractText($payload);
        $status  = (string) data_get($payload, 'status');
        $reason  = data_get($payload, 'incomplete_details.reason');

        if ($script !== '') {
            $this->logReceived($meditationId, $script, $payload, 'first');
            return $script;
        }

        // Retry once if we hit the token cap
        if ($reason === 'max_output_tokens') {
            Log::info('🧘 2.2 retrying due to token cap', [
                'meditationId' => $meditationId,
                'reason'       => $reason,
            ]);

            $retryPrompt = $prompt . "\n\nSTRICT LENGTH: Keep it to about 140–160 words (under ~900 characters).";

            $retryBody = [
                'model'             => $model,
                'input'             => $retryPrompt,
                'max_output_tokens' => 700, // generous; prompt constrains length
            ];

            $payload = $this->awaitResponse($retryBody);
            $script  = $this->extractText($payload);

            if ($script !== '') {
                $this->logReceived($meditationId, $script, $payload, 'retry');
                return $script;
            }
        }

        Log::error('OpenAI empty text in response', [
            'status'             => $status ?: null,
            'incomplete_details' => data_get($payload, 'incomplete_details'),
            'keys_lvl1'          => is_array($payload) ? array_slice(array_keys($payload), 0, 12) : gettype($payload),
            'output0'            => data_get($payload, 'output.0.type'),
            'content0'           => data_get($payload, 'output.0.content.0.type'),
        ]);

        throw new RuntimeException('OpenAI did not return text (after retry).');
    }

    /**
     * POSTs a response and polls /responses/{id} until completed or text appears.
     */
    private function awaitResponse(array $body): array
    {
        $start   = microtime(true);
        $payload = $this->post('/responses', $body)->json();
        $id      = (string) data_get($payload, 'id');
        $status  = (string) data_get($payload, 'status');

        Log::info('🧘 2.1a responses POST ack', compact('id', 'status'));

        // Immediate text?
        if ($this->extractText($payload) !== '') {
            return $payload;
        }

        // Poll up to ~90s (0.5s -> 2s backoff)
        $tries = 0;
        while ($tries < 45 && $id) {
            usleep(min(500000 + $tries * 500000, 2000000));
            $payload = $this->get("/responses/{$id}")->json();
            $status  = (string) data_get($payload, 'status');
            $tries++;

            if ($this->extractText($payload) !== '') {
                Log::info('🧘 2.1b responses final (text available)', [
                    'id'        => $id,
                    'status'    => $status,
                    'tries'     => $tries,
                    'elapsed_s' => round(microtime(true) - $start, 2),
                ]);
                return $payload;
            }

            if (in_array($status, ['failed', 'cancelled'], true)) {
                break;
            }
        }

        Log::info('🧘 2.1b responses final (no text)', [
            'id'        => $id,
            'status'    => $status,
            'tries'     => $tries,
            'elapsed_s' => round(microtime(true) - $start, 2),
        ]);

        return $payload;
    }

    /**
     * Robustly pull text from any of the response shapes.
     */
    private function extractText($payload): string
    {
        // 1) top-level output_text
        $txt = (string) (data_get($payload, 'output_text') ?? '');
        if ($txt !== '') return $txt;

        // 2) join output[*].content[*].text where type === output_text
        $chunks = collect(data_get($payload, 'output', []))
            ->flatMap(static fn ($msg) => (array) data_get($msg, 'content', []))
            ->filter(static fn ($c) => data_get($c, 'type') === 'output_text' && filled(data_get($c, 'text')))
            ->map(static fn ($c) => (string) data_get($c, 'text'));

        $txt = $chunks->implode('');
        if ($txt !== '') return $txt;

        // 3) legacy-ish fallback
        return (string) (data_get($payload, 'choices.0.message.content') ?? '');
    }

    private function logReceived(int $meditationId, string $script, array $payload, string $which): void
    {
        Log::info("🧘 2.3 script received ({$which})", [
            'meditationId' => $meditationId,
            'chars'        => mb_strlen($script),
            'preview'      => mb_substr($script, 0, 120) . (mb_strlen($script) > 120 ? '…' : ''),
            'id'           => data_get($payload, 'id'),
            'status'       => data_get($payload, 'status'),
        ]);
    }

    protected function buildPrompt(int $meditationId): string
    {
        $m = Meditation::findOrFail($meditationId);

        return "You are a meditation expert. Create a personalised meditation script for {$m->first_name}. "
             . "Their goals are: {$m->goals}. Their challenges are: {$m->challenges}. "
             . "Use any influences from their star sign using their date of birth: {$m->birth_date}. "
             . "The script should be calming, supportive, and tailored to their needs. "
             . "Make the meditation ~1 minute long when spoken.";
    }
}
