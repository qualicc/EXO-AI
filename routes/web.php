<?php

use App\Http\Controllers\ExoplanetController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ExoplanetController::class, 'index'])->name('home');
Route::get('/exoplanets/{exoplanet}', [ExoplanetController::class, 'show'])->name('exoplanets.show');
Route::get('/exoplanets/{exoplanet}/plots/{plotKey}', [ExoplanetController::class, 'plot'])
    ->where('plotKey', '[A-Za-z0-9_-]+')
    ->name('exoplanets.plots.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [ExoplanetController::class, 'index'])->name('dashboard');
    Route::post('/exoplanets/{exoplanet}/predictions', [ExoplanetController::class, 'storePrediction'])->name('exoplanets.predictions.store');
    Route::get('/exoplanets/{exoplanet}/queue-status', [ExoplanetController::class, 'queueStatus'])->name('exoplanets.queue-status');
});

require __DIR__.'/settings.php';
