<?php

use App\Http\Controllers\Auth\GoogleLoginController;
use App\Http\Controllers\Auth\SlushLoginController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::post('login/google', [GoogleLoginController::class, 'store'])
    ->middleware(['guest', 'throttle:google-login'])
    ->name('login.google.store');

Route::get('login/slush/challenge', [SlushLoginController::class, 'challenge'])
    ->middleware(['guest', 'throttle:slush-login'])
    ->name('login.slush.challenge');

Route::post('login/slush', [SlushLoginController::class, 'store'])
    ->middleware(['guest', 'throttle:slush-login'])
    ->name('login.slush.store');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/settings.php';
