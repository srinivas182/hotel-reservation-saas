<?php

declare(strict_types=1);

use App\Domains\Tenancy\Http\Controllers\SwitchHotelController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::post('hotels/{hotel}/switch', SwitchHotelController::class)->name('hotels.switch');
});

require __DIR__.'/settings.php';
