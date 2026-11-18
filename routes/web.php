<?php

use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationEstimateController;
use App\Http\Controllers\GenerationImageController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');
Route::inertia('explore', 'Explore')->name('explore');
Route::inertia('models', 'Models')->name('models');

// Stored generated images: signed links, usable without signing in (pages and shared links).
Route::get('images/{generation}', GenerationImageController::class)
    ->whereUuid('generation')
    ->middleware(['signed:relative', 'throttle:120,1'])
    ->name('generations.image');

Route::middleware(['auth', 'verified'])->group(function () {
    // The app is the generate page; keep /dashboard (Fortify's home) as an alias for it.
    Route::redirect('dashboard', '/generate')->name('dashboard');

    Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('generations/{generation}', [GenerationController::class, 'show'])
        ->whereUuid('generation')
        ->name('generations.show');
    Route::get('generations/{generation}/download', [GenerationController::class, 'download'])
        ->whereUuid('generation')
        ->middleware('throttle:30,1')
        ->name('generations.download');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('generations.store');
    // Each estimate may fetch Pixelforge's pricing when the cache has expired.
    Route::post('generations/estimate', GenerationEstimateController::class)
        ->middleware('throttle:60,1')
        ->name('generations.estimate');
});

require __DIR__.'/settings.php';
