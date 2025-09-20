<?php

use App\Http\Controllers\SunoWebhookController;
use Illuminate\Support\Facades\Route;
use App\Models\Meditation;

Route::post('/webhooks/suno', SunoWebhookController::class)
    ->middleware('suno.webhook')   // shared-secret auth
    ->name('webhooks.suno');

    
Route::get('/meditation/{meditation}/download', function (Meditation $meditation) {
    return response()->json([
        'ready'           => (bool) $meditation->meditation_url,
        'meditation_url'  => $meditation->meditation_url,
    ]);
})->name('meditation.download-status');
