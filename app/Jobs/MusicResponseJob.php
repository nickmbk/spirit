<?php

namespace App\Jobs;

use App\Models\Meditation;
use App\Services\Api\GoogleDrive;
use App\Services\DatabaseLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Excepetion;

class MusicResponseJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    protected $meditationId;
    protected $meditationDate;
    protected $voicePath;
    protected $musicPath;

    public function __construct($meditationId, $meditationDate, $voicePath, $musicPath)
    {
        $this->meditationId = $meditationId;
        $this->meditationDate = $meditationDate;
        $this->voicePath = $voicePath;
        $this->musicPath = $musicPath;
    }

    public function handle(): void
    {
        // Upload to Google Drive
        $googleDrive = new GoogleDrive();
        $googleFileName = 'meditation_music_' . $this->meditationId . '_' . $this->meditationDate . '.wav';
        $uploadPath = $googleDrive->uploadPath(Storage::path($this->musicPath), $googleFileName, 'audio/wav', 'test');
        $googleDrive->makeAnyoneReader($uploadPath);
        $musicDrivePath = $googleDrive->publicDownloadLink($uploadPath);

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

        MixAudioJob::dispatch($this->meditationId, $this->meditationDate, $this->voicePath, $this->musicPath);
    }
}
