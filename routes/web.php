<?php

declare(strict_types=1);

use App\Domains\Identity\Http\Controllers\LocaleController;
use App\Domains\Platform\Http\Controllers\HealthController;
use App\Domains\Tenancy\Http\Controllers\SwitchHotelController;
use App\Http\Controllers\CspReportController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? to_route('dashboard') : to_route('login'))->name('home');

Route::post('locale', LocaleController::class)->middleware('throttle:30,1')->name('locale.update');

Route::get('health', HealthController::class)->middleware('throttle:30,1')->name('health');

Route::post('csp-report', CspReportController::class)
    ->middleware('throttle:60,1')
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->name('csp.report');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::post('hotels/{hotel}/switch', SwitchHotelController::class)->name('hotels.switch');
});

require __DIR__.'/settings.php';
require __DIR__.'/platform.php';
require __DIR__.'/hotel.php';
