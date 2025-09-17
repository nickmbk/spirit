<?php

namespace App\Services\Api;

use App\Models\Meditation;

class OpenAi extends BaseApi
{
    protected function baseUrl(): string
    {
        return config('services.openai.base_url');
    }

    protected function token(): ?string
    {
        return config('services.openai.key');
    }

    protected function defaultHeaders(): array
    {
        return parent::defaultHeaders() + [
            'Authorization' => 'Bearer ' . $this->token(),
        ];
    }

    public function createScript(int $meditationId, string $model = 'gpt-5'): string
    {
        $input = $this->buildPrompt($meditationId);
        $body = [
            'model' => $model,
            'input' => $input,
            'max_tokens' => 500,
            'temperature' => 0.2
        ];
        return $this->post('/responses', $body)->json('output[0].content[0].text');
    }

    protected function buildPrompt(int $meditationId): string
    {
        $meditation = Meditation::find($meditationId);
        $firstName = $meditation->first_name;
        $birthDate = $meditation->birth_date;
        $goals = $meditation->goals;
        $challenges = $meditation->challenges;

        return 
            "You are a meditation expert. Create a personalized meditation script for $firstName. Their goals are: $goals. Their challenges are: $challenges. Use any influences from their star sign using their date of birth: $birthDate. The script should be calming, supportive, and tailored to their needs. Make the meditation one minute long when spoken."
        ;
    }
}


// <?php

// namespace App\Services\Api;

// use Illuminate\Http\Client\Response;
// use App\Services\Api\BaseApi;

// class OpenAi extends BaseApi
// {
//     public function __construct()
//     {
//         parent::__construct(
//             baseUrl: config('services.openai.base_url'),
//             token: config('services.openai.api_key')
//         );
//     }

//     public function chat(array $input, int $maxTokens = ): Response
//     {
//         return $this->post('/responses', [
//             'model' => 'gpt-5',
//             'input' => $input,
//             'max_tokens' => $maxTokens,
//             'temperature' => 0.2
//         ]);
//     }

//     public function generateScript(string $prompt, int $maxTokens = 500): string
//     {
//         $response = $this->chat([
//             ['role' => 'system', 'content' => "You are a meditation expert"],
//             ['role' => 'user', 'content' => $prompt], // TODO: add the prompt here
//         ], $maxTokens);

//         return $response->json('choices.0.message.content') ?? '';
//     }

//     public function generateFakeScript() {
//         return "
//             Welcome to your personalized meditation, user. 

//             Find a comfortable position, either sitting or lying down. Close your eyes gently, and take a deep breath in through your nose... and slowly exhale through your mouth.

//             Let's begin by focusing on your breath. Breathe in slowly for a count of four... one, two, three, four. Hold for a moment... and now exhale for a count of six... one, two, three, four, five, six.

//             user, as you continue breathing naturally, imagine yourself in a peaceful place. This could be a quiet forest, a calm beach, or anywhere that brings you tranquility. Feel the serenity of this place washing over you.

//             With each breath, you're releasing any tension from your day. Your shoulders are relaxing... your jaw is unclenching... your entire body is becoming more and more at ease.

//             Now, user, let's set a gentle intention. Whatever brought you to this meditation today - whether it's stress, anxiety, or simply the need for peace - know that this time is yours. You deserve this moment of calm.

//             Take three more deep breaths with me. In... and out. In... and out. In... and out.

//             As we conclude, slowly wiggle your fingers and toes. Take a moment to appreciate this feeling of calm that you can return to anytime you need it.

//             When you're ready, user, gently open your eyes. You are refreshed, peaceful, and ready to continue your day with clarity and calm.

//             Thank you for taking this time for yourself.
//         ";
//     }
// }
