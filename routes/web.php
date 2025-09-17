<?php

use App\Http\Controllers\MeditationController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/google.php';

Route::get('/', function () {
    return view('home/index');
});

Route::view('/meditation', 'meditation.index');
Route::post('/meditation', [MeditationController::class, 'index'])
    ->name('meditation.index');
Route::get('/meditation/thanks', [MeditationController::class, 'thanks'])
    ->name('meditation.thanks');