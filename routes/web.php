<?php

use App\Http\Controllers\MeditationController;
use Illuminate\Support\Facades\Route;
use App\Models\Meditation;

require __DIR__.'/google.php';

Route::view('/', 'meditation.index');
Route::post('/', [MeditationController::class, 'index'])
    ->name('meditation.index');
Route::get('/{meditation}/thanks', function (Meditation $meditation) {
    return view('meditation.thanks', ['meditation' => $meditation]);
})->name('meditation.thanks');
