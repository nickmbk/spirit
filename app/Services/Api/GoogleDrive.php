<?php

namespace App\Services\Api;

use Google\Client as GoogleClient;
use Google\Http\MediaFileUpload;
use Google\Service\Drive as GoogleDriveService;
use Google\Service\Drive\DriveFile;

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

        // Scope: DRIVE is broad (full access). If you want narrower, use DRIVE_FILE.
        $this->client->setScopes([GoogleDriveService::DRIVE]);

        // ✅ Correct way to use the refresh token
        $refresh = (string) config('services.google_drive.refresh_token');
        if (!$refresh) {
            throw new \RuntimeException('Missing GOOGLE_DRIVE_REFRESH_TOKEN in .env');
        }
        $token = $this->client->fetchAccessTokenWithRefreshToken($refresh);
        if (isset($token['error'])) {
            throw new \RuntimeException('Google token refresh failed: ' . json_encode($token));
        }
        $this->client->setAccessToken($token);
        $this->client->setRefreshToken($refresh);

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



// <?php

// namespace App\Services\Api;

// use Google\Client as GoogleClient;
// use Google\Service\Drive as GoogleDriveService;
// use Google\Service\Drive\DriveFile;
// use Google\Service\Drive\Permission;
// use Illuminate\Http\Client\Response as HttpResponse;

// class GoogleDrive
// {
//     protected GoogleClient $google;
//     protected GoogleDriveService $drive;

//     public function __construct()
//     {
//         // --- OAuth2 setup
//         $this->google = new GoogleClient();
//         $this->google->setClientId(config('services.google_drive.client_id'));
//         $this->google->setClientSecret(config('services.google_drive.client_secret'));
//         $this->google->setAccessType('offline');

//         // IMPORTANT: use the SAME scope you used when you created the refresh token
//         // If you authorised with drive.file in the Playground, keep drive.file here too.
//         $this->google->setScopes(['https://www.googleapis.com/auth/drive.file']);

//         // Exchange refresh token -> access token (and auto-refresh later)
//         $token = $this->google->fetchAccessTokenWithRefreshToken(
//             config('services.google_drive.refresh_token')
//         );

//         if (isset($token['error'])) {
//             // Helps you see exactly what's wrong (mismatched client, revoked token, etc.)
//             throw new \RuntimeException('Google OAuth token exchange failed: ' . json_encode($token));
//         }

//         $this->drive = new GoogleDriveService($this->google);
//     }

//     /**
//      * Find or create a folder named "{$meditationId}_{$meditationDate}" and return its ID.
//      */
//     protected function getOrCreateTestFolderId(int $meditationId, string $meditationDate): string
//     {
//         // Query for existing folder
//         $name = "{$meditationId}_{$meditationDate}";
//         $q = sprintf(
//             "mimeType='application/vnd.google-apps.folder' and name='%s' and trashed=false",
//             addcslashes($name, "'")
//         );

//         $result = $this->drive->files->listFiles([
//             'q' => $q,
//             'pageSize' => 1,
//             'fields' => 'files(id,name)',
//         ]);

//         if (!empty($result->files) && !empty($result->files[0]->id)) {
//             return $result->files[0]->id;
//         }

//         // Create folder
//         $folderMeta = new DriveFile([
//             'name' => $name,
//             'mimeType' => 'application/vnd.google-apps.folder',
//         ]);

//         $created = $this->drive->files->create($folderMeta, [
//             'fields' => 'id',
//         ]);

//         return $created->id;
//     }

//     /**
//      * Upload a file to Drive into the per-meditation folder.
//      *
//      * @param string                $name         a short source tag (e.g., "elevenlabs")
//      * @param int                   $meditationId id
//      * @param string|HttpResponse   $audio        binary string or an HTTP client Response
//      * @param string                $createdAt    e.g. "20250824-153210"
//      * @param string                $ext          wav|mp3|m4a
//      * @param bool                  $public       make file public (anyone with link)
//      * @return array{id:string,name:string,view:?string,download:?string}
//      */
//     public function uploadFile(
//         string $name,
//         int $meditationId,
//         string|HttpResponse $audio,
//         string $createdAt,
//         string $ext,
//         bool $public = true
//     ): array {
//         // Resolve binary
//         $binary = $audio instanceof HttpResponse ? $audio->body() : $audio;

//         // MIME type
//         $mime = match (strtolower($ext)) {
//             'wav' => 'audio/wav',
//             'mp3' => 'audio/mpeg',
//             'm4a' => 'audio/mp4',
//             default => 'application/octet-stream',
//         };

//         // Final file name
//         $fileName = "meditation-{$meditationId}_{$createdAt}_{$name}.{$ext}";

//         // Ensure folder exists
//         $folderId = $this->getOrCreateTestFolderId($meditationId, $createdAt);

//         // Prepare metadata
//         $fileMeta = new DriveFile([
//             'name'    => $fileName,
//             'parents' => [$folderId],
//         ]);

//         // --- Upload (multipart: metadata + media)
//         $created = $this->drive->files->create($fileMeta, [
//             'data'        => $binary,
//             'mimeType'    => $mime,
//             'uploadType'  => 'multipart',
//             'fields'      => 'id,name,webViewLink,webContentLink',
//         ]);

//         // Optionally make public
//         if ($public) {
//             $perm = new Permission([
//                 'type' => 'anyone',
//                 'role' => 'reader',
//             ]);
//             $this->drive->permissions->create($created->id, $perm, [
//                 'fields' => 'id',
//             ]);

//             // Re-fetch to ensure links are present
//             $created = $this->drive->files->get($created->id, [
//                 'fields' => 'id,name,webViewLink,webContentLink',
//             ]);
//         }

//         return [
//             'id'       => $created->id,
//             'name'     => $created->name,
//             'view'     => $created->webViewLink   ?? null,
//             'download' => $created->webContentLink ?? null,
//         ];
//     }
// }
