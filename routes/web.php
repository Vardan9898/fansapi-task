<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// Basic operator dashboard. Open locally; elsewhere only allowlisted users (Gate "operate").
Route::middleware('can:operate')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::post('/profiles/{profile}/refresh', [ProfileController::class, 'refresh'])->name('profiles.refresh');
    Route::post('/profiles/{profile}/move', [ProfileController::class, 'move'])->name('profiles.move');

    Route::post('/accounts/{account}/block', [AccountController::class, 'block'])->name('accounts.block');
    Route::post('/accounts/{account}/unblock', [AccountController::class, 'unblock'])->name('accounts.unblock');
    Route::post('/accounts/{account}/move-profiles', [AccountController::class, 'moveProfiles'])->name('accounts.move-profiles');
});
