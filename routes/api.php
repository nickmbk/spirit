<?php

use App\Http\Controllers\SunoWebhookController;
use Illuminate\Support\Facades\Route;
use App\Models\Meditation;
use App\Models\JobLog;

Route::post('/webhooks/suno', SunoWebhookController::class)
    ->middleware('suno.webhook')   // shared-secret auth
    ->name('webhooks.suno');

    
Route::get('/meditation/{meditation}/download', function (Meditation $meditation) {
    $ready = (bool) $meditation->meditation_url;

    // Look for any error logs tied to this meditation after it was created
    $failedLog = JobLog::query()
        ->where('level', 'error')
        ->where('context->meditation_id', $meditation->id)
        ->where('created_at', '>=', $meditation->created_at)
        ->latest('id')
        ->first();

    return response()->json([
        'ready'           => $ready,
        'meditation_url'  => $meditation->meditation_url,
        'status'          => $failedLog ? 'failed' : ($ready ? 'complete' : 'pending'),
        'error'           => $failedLog?->message,
    ]);
})->name('meditation.download-status');
