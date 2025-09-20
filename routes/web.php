<?php

use App\Http\Controllers\MeditationController;
use Illuminate\Support\Facades\Route;
use App\Models\Meditation;

require __DIR__.'/google.php';

Route::get('/', function () {
    return view('home/index');
});

Route::view('/meditation', 'meditation.index');
Route::post('/meditation', [MeditationController::class, 'index'])
    ->name('meditation.index');
Route::get('/meditation/{meditation}/thanks', function (Meditation $meditation) {
    return view('meditation.thanks', ['meditation' => $meditation]);
})->name('meditation.thanks');
