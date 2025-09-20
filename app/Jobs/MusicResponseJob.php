<?php

namespace App\Jobs;

use App\Models\Meditation;
use App\Services\Api\GoogleDrive;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Excepetion;
use App\Services\DatabaseLogger;

class MusicResponseJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    protected $meditationId;
    protected $meditationDate;
    protected $tempVoicePath;
    protected $tempMusicPath;

    public function __construct($meditationId, $meditationDate, $tempVoicePath, $tempMusicPath)
    {
        $this->meditationId = $meditationId;
        $this->meditationDate = $meditationDate;
        $this->tempVoicePath = $tempVoicePath;
        $this->tempMusicPath = $tempMusicPath;
    }

    public int $timeout = 900;       // 15 min for long tasks (FFmpeg)
    public bool $failOnTimeout = true;

    public int $tries = 3;           // or higher for pollers
    public function backoff(): array  // exponential backoff for retries
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        // Upload the music file to Google Drive
        $googleDrive = new GoogleDrive();
        $googleFileName = 'meditation_music_' . $this->meditationId . '_' . $this->meditationDate . '.wav';
        $uploadPath = $googleDrive->uploadPath($this->tempMusicPath, $googleFileName, 'audio/wav', 'test');
        $googleDrive->makeAnyoneReader($uploadPath);
        $musicDrivePath = $googleDrive->publicDownloadLink($uploadPath);

        Log::info('3.2 uploading music to google drive', [
            'meditationId' => $this->meditationId,
            'meditationDate' => $this->meditationDate,
            'musicPath'    => $this->tempMusicPath,
            'nextMusicPath' => Storage::path($this->tempMusicPath),
            'voicePath'    => $this->tempVoicePath,
            'nextVoicePath' => Storage::path($this->tempVoicePath),
        ]);

        // Save to meditation record
        $meditation = Meditation::find($this->meditationId);
        $meditation->music_url = $musicDrivePath;
        $meditation->save();

        Log::info('3.3 music uploaded and meditation record updated', [
            'meditationId' => $this->meditationId,
            'musicDrivePath' => $musicDrivePath,
        ]);
        DatabaseLogger::info(self::class, 'Suno music uploaded and meditation record updated', [
            'meditation_id' => $this->meditationId,
            'meditation_date' => $this->meditationDate,
            'music_drive_path' => $musicDrivePath,
        ], $this->job?->getJobId());

        MixAudioJob::dispatch($this->meditationId, $this->meditationDate, $this->tempVoicePath, $this->tempMusicPath);
    }
}
