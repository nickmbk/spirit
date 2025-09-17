<?php

namespace App\Jobs;

use App\Models\Meditation;
use App\Services\AudioMix;
use App\Services\Api\GoogleDrive;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MixAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $meditationId;
    protected $meditationDate;
    protected $voicePath;
    protected $musicPath;
    protected $offsetMs;
    protected $tailMs;
    protected $fadeOut;

    public function __construct($meditationId, $meditationDate, $voicePath, $musicPath, $offsetMs = 5000, $tailMs = 5000, $fadeOut = true)
    {
        $this->meditationId = $meditationId;
        $this->meditationDate = $meditationDate;
        $this->voicePath = $voicePath;
        $this->musicPath = $musicPath;
        $this->offsetMs = $offsetMs;
        $this->tailMs = $tailMs;
        $this->fadeOut = $fadeOut;
    }

    public function handle(): void
    {
        Log::info('🎛️ 4.1 Starting meditation mix', [
            'meditationId' => $this->meditationId,
            'meditationDate' => $this->meditationDate,
            'voicePath'    => $this->voicePath,
            'musicPath'    => $this->musicPath,
        ]);

        // 1) Load the files from storage (local or remote)
        $voiceBinary = $this->loadFile($this->voicePath);
        $musicBinary = $this->loadFile($this->musicPath);

        // 2) Mix audio using FFmpeg
        $mixer = new AudioMix();
        $mix = $mixer->mixWithOffsetAndLoop(
            voiceBinary: $voiceBinary,
            musicBinary: $musicBinary,
            offsetMs:    5000,
            tailMs:      5000,
            voiceVol:    0.85,
            musicVol:    0.15,
            fadeOut:     true,
            fadeMs:      5000,
            outFormat:   'mp3'
        );

        // 3) Save the mixed track to Google Drive/Test
        // $googleDrive = new GoogleDrive();
        // $meditationPath = $googleDrive->uploadFile('meditation', $this->meditationId, $mix, $this->meditationDate, 'mp3')->download;

        //4 test save
        $meditationAudio = mix(['binary']);
        $tempPath = $this->saveTempFile($meditationAudio);

        $googleDrive = new GoogleDrive();
        $googleFileName = 'meditation_' . $this->meditationId . '_' . $this->meditationDate . '.mp3';
        $uploadPath = $googleDrive->uploadPath($meditationAudio, $googleFileName, 'audio/mp3', 'test');
        $googleDrive->makeAnyoneReader($uploadPath);
        $meditationPath = $googleDrive->publicDownloadLink($uploadPath);
        
        $meditation = Meditation::find($this->meditationId);
        $meditation->meditation_url = $meditationPath;
        $meditation->save();
    }

    /**
     * Load file from local storage or external URL.
     */
    private function loadFile(string $path): string
    {
        // If it’s a full URL (e.g., from Google Drive)
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            $resp = \Illuminate\Support\Facades\Http::timeout(120)->get($path);
            $resp->throw();
            return $resp->body();
        }
        // Otherwise treat it as local storage
        return Storage::disk('public')->get($path);
    }

    private function saveTempFile(string $file): string
    {
        $fileName = 'meditation_' . $this->meditationId . '_' . $this->meditationDate . '.mp3';

        $absPath = storage_path('app/tmp/' . $fileName);

        // make sure tmp directory exists
        if (!is_dir(dirname($absPath))) {
            mkdir(dirname($absPath), 0775, true);
        }

        // save the bytes to disk
        $saved = file_put_contents($absPath, $file);

        if (!$saved) {
            throw new \Exception('Failed to save temporary audio file: ' . $absPath);
        }

        return $absPath;
    }
}
