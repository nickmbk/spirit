<?php

namespace App\Services\Api;

use Google\Client as GoogleClient;
use Google\Http\MediaFileUpload;
use Google\Service\Drive as GoogleDriveService;
use Google\Service\Drive\DriveFile;
use Illuminate\Support\Facades\Log;

class GoogleDrive
{
    protected GoogleClient $client;
    protected GoogleDriveService $drive;
    protected ?string $rootFolderId;

    public function __construct()
    {
        $this->client = new GoogleClient();
        $this->client->setClientId(config('services.google_drive.client_id'));
        $this->client->setClientSecret(config('services.google_drive.client_secret'));
        $this->client->setAccessType('offline');
        $this->client->setScopes([GoogleDriveService::DRIVE_FILE]); // keep same scope you minted with

        $refresh = trim((string) config('services.google_drive.refresh_token'));
        if ($refresh === '') {
            throw new \RuntimeException('Missing GOOGLE_DRIVE_REFRESH_TOKEN in .env');
        }

        // Get a fresh access token using the refresh token
        $token = $this->client->fetchAccessTokenWithRefreshToken($refresh);
        if (isset($token['error'])) {
            throw new \RuntimeException('Google token refresh failed: ' . json_encode($token));
        }

        // Ensure the refresh_token stays on the client (Google often omits it in the response)
        $token['refresh_token'] = $refresh;

        // Set the full token payload on the client
        $this->client->setAccessToken($token);

        $this->drive = new GoogleDriveService($this->client);
        $this->rootFolderId = config('services.google_drive.folder_id');
    }

    /**
     * Upload raw BYTES to Drive (handy if you already have file contents in memory).
     * Note: for BIG WAVs prefer uploadPath() which streams/chunks.
     */
    public function uploadSource(string $bytes, string $name, ?string $mime = null, ?string $folderName = 'test'): array
    {
        $folderId = $folderName ? $this->findFolder($folderName) : $this->rootFolderId;

        if ($mime === null) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'mp3' => 'audio/mpeg',
                'wav' => 'audio/wav', // 'audio/x-wav' also fine
                default => 'application/octet-stream',
            };
        }

        $fileMetadata = new DriveFile([
            'name'    => $name,
            'parents' => $folderId ? [$folderId] : [],
        ]);

        // ❗️Bug fix: your original used $bytes without defining it; use the $bytes param.
        $file = $this->drive->files->create($fileMetadata, [
            'data'       => $bytes,
            'mimeType'   => $mime,
            'uploadType' => 'multipart',
            'fields'     => 'id, name, mimeType, size, parents, webViewLink, webContentLink',
        ]);

        return [
            'id'               => $file->id,
            'name'             => $file->name,
            'mime_type'        => $file->mimeType,
            'size'             => isset($file->size) ? (int) $file->size : null,
            'parent_id'        => $folderId,
            'web_view_link'    => $file->webViewLink ?? null,
            'web_content_link' => $file->webContentLink ?? null,
        ];
    }

    /**
     * Upload from a LOCAL PATH using a resumable, chunked upload (best for WAV).
     */
    public function uploadPath(string $localPath, string $name, ?string $mime = 'audio/wav', ?string $folderName = 'test'): string
    {
        $folderId = $folderName ? $this->findFolder($folderName) : $this->rootFolderId;

        $meta = new DriveFile([
            'name'    => $name,
            'parents' => $folderId ? [$folderId] : [],
        ]);

        $chunkSize = 1 * 1024 * 1024; // 1MB chunks
        $client = $this->drive->getClient();
        $client->setDefer(true);

        $request = $this->drive->files->create($meta, [
            'uploadType' => 'resumable',
            'fields'     => 'id',
        ]);

        $media = new MediaFileUpload($client, $request, $mime, null, true, $chunkSize);
        $media->setFileSize(filesize($localPath));

        $handle = fopen($localPath, 'rb');
        $status = false;
        while (!$status && !feof($handle)) {
            $status = $media->nextChunk(fread($handle, $chunkSize));
        }
        fclose($handle);
        $client->setDefer(false);

        /** @var \Google\Service\Drive\DriveFile $uploaded */
        $uploaded = $status;
        return $uploaded->id;
    }

    /**
     * Find (or create) a folder under the configured root folder and return its ID.
     */
    public function findFolder(string $folderName = 'test'): string
    {
        $escapedName = addcslashes($folderName, "'");
        $q = sprintf(
            "mimeType = 'application/vnd.google-apps.folder' and name = '%s' and trashed = false",
            $escapedName
        );

        // If a root/parent folder ID is set, limit search to it.
        if ($this->rootFolderId) {
            $q .= sprintf(" and '%s' in parents", $this->rootFolderId);
        }

        $res = $this->drive->files->listFiles([
            'q'        => $q,
            'fields'   => 'files(id, name)',
            'pageSize' => 1,
            'spaces'   => 'drive',
            // For Shared Drives support you'd add: 'supportsAllDrives' => true, 'includeItemsFromAllDrives' => true,
        ]);

        if (!empty($res->files)) {
            return $res->files[0]->id;
        }

        // Create it if not found
        $folder = new DriveFile([
            'name'     => $folderName,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents'  => $this->rootFolderId ? [$this->rootFolderId] : [],
        ]);

        $created = $this->drive->files->create($folder, ['fields' => 'id, name']);
        return $created->id;
    }

    /**
     * Make a file public by link.
     */
    public function makeAnyoneReader(string $fileId): void
    {
        $perm = new \Google\Service\Drive\Permission(['type' => 'anyone', 'role' => 'reader']);
        $this->drive->permissions->create($fileId, $perm, ['fields' => 'id']);
    }

    /**
     * Build a direct-ish download URL users can click.
     */
    public function publicDownloadLink(string $fileId): string
    {
        return "https://drive.google.com/uc?id={$fileId}&export=download";
    }
}
