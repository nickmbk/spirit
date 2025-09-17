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

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // create suno connection
        $suno = new Suno();
        $sunoTaskId = $suno->createMusic($this->meditationId);
        Log::info('3.1 suno created', ['suno' => 'suno']);
        DatabaseLogger::info(self::class, 'Creation of Suno Music Service has started', [
            'meditation_id' => $this->meditationId,
            'meditation_date' => $this->meditationDate,
            'suno_task_id' => $sunoTaskId
        ], $this->job?->getJobId());
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

        // MixAudio::dispatch($this->meditationId, $this->meditationDate, $meditation->voice_url, $meditation->music_url);
        // Create the track
        // $music = $suno->createTrack($prompt); //change the prompt
        //This is for testing, its fake
        // $musicPath = $suno->generateFakeAudio();
        // Log::info('3.2 fake audio generated', ['musicPath' => 'musicPath']);
        // get the id of the newly created track
        // $musicId = $music->json('data.taskId');
        
        // $musicTrack = $suno->findTrack($musicId);
        
        // Save the generated audio path to the database or perform any other actions
        // $googleDrive = new GoogleDrive();
        // $musicPath = $googleDrive->uploadFile('suno', $this->meditationId, $musicTrack, $this->meditationDate, 'wav')->download;
        // $meditation = Meditation::find($this->meditationId);
        // $meditation->music_url = $musicPath;
        // $meditation->save();
        // Log::info('3.3 fake audio saved to meditation db', ['meditation' => 'meditation']);
        // MixAudio::dispatch($this->meditationId, $this->meditationDate, $meditation->voice_url, $meditation->music_url);
    }
}


// {
//   "code": 200,
//   "data": {
//     "callbackType": "first",
//     "data": [
//       {
//         "audio_url": "https://apiboxfiles.erweima.ai/ZTMyMjVlMmQtMTZlYy00ZjVjLTlkY2MtMTZmZDU0ODYzMWUx.mp3",
//         "createTime": 1758044664461,
//         "duration": 163.88,
//         "id": "e3225e2d-16ec-4f5c-9dcc-16fd548631e1",
//         "image_url": "https://apiboxfiles.erweima.ai/ZTMyMjVlMmQtMTZlYy00ZjVjLTlkY2MtMTZmZDU0ODYzMWUx.jpeg",
//         "model_name": "chirp-v3-5",
//         "prompt": "[Verse]\nThe moon hums low the night is still\nA quiet breath on the windowsill\nStars whisper secrets to the sky\n\n[Chorus]\nWhispering tides calling me home\nSoft as the sea gentle as foam\nWhispering tides I’m not alone\n\n[Verse 2]\nThe breeze it speaks in lullabies\nA tender touch that never lies\nDreams drift like clouds in endless blue\n\n[Bridge]\nOh the waves they know my name\nThey carry my fears and leave no blame\nIn their arms I feel the same\n\n[Chorus]\nWhispering tides calling me home\nSoft as the sea gentle as foam\nWhispering tides I’m not alone",
//         "source_audio_url": "https://cdn1.suno.ai/e3225e2d-16ec-4f5c-9dcc-16fd548631e1.mp3",
//         "source_image_url": "https://cdn2.suno.ai/image_e3225e2d-16ec-4f5c-9dcc-16fd548631e1.jpeg",
//         "source_stream_audio_url": "https://audiopipe.suno.ai/?item_id=e3225e2d-16ec-4f5c-9dcc-16fd548631e1",
//         "stream_audio_url": "https://mfile.erweima.ai/ZTMyMjVlMmQtMTZlYy00ZjVjLTlkY2MtMTZmZDU0ODYzMWUx",
//         "tags": "relaxing, soft, soft and soothing with gentle dynamics, piano-driven, calm",
//         "title": "Whispering Tides"
//       },
//       {
//         "audio_url": "",
//         "createTime": 1758044664461,
//         "id": "5ee8f9ef-a6e3-46a0-a8eb-1c3802a2fb2f",
//         "image_url": "https://apiboxfiles.erweima.ai/NWVlOGY5ZWYtYTZlMy00NmEwLWE4ZWItMWMzODAyYTJmYjJm.jpeg",
//         "model_name": "chirp-v3-5",
//         "prompt": "[Verse]\nThe moon hums low the night is still\nA quiet breath on the windowsill\nStars whisper secrets to the sky\n\n[Chorus]\nWhispering tides calling me home\nSoft as the sea gentle as foam\nWhispering tides I’m not alone\n\n[Verse 2]\nThe breeze it speaks in lullabies\nA tender touch that never lies\nDreams drift like clouds in endless blue\n\n[Bridge]\nOh the waves they know my name\nThey carry my fears and leave no blame\nIn their arms I feel the same\n\n[Chorus]\nWhispering tides calling me home\nSoft as the sea gentle as foam\nWhispering tides I’m not alone",
//         "source_image_url": "https://cdn2.suno.ai/image_5ee8f9ef-a6e3-46a0-a8eb-1c3802a2fb2f.jpeg",
//         "source_stream_audio_url": "https://audiopipe.suno.ai/?item_id=5ee8f9ef-a6e3-46a0-a8eb-1c3802a2fb2f",
//         "stream_audio_url": "https://mfile.erweima.ai/NWVlOGY5ZWYtYTZlMy00NmEwLWE4ZWItMWMzODAyYTJmYjJm",
//         "tags": "relaxing, soft, soft and soothing with gentle dynamics, piano-driven, calm",
//         "title": "Whispering Tides"
//       }
//     ],
//     "task_id": "65e563474bd7b6485dfb229eb92324a8"
//   },
//   "msg": "First audio generated successfully."
// }