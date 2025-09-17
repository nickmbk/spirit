<?php

use App\Http\Controllers\SunoWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/suno', SunoWebhookController::class)
    ->middleware('suno.webhook')   // shared-secret auth
    ->name('webhooks.suno');