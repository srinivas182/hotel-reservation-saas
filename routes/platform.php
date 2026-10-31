<?php

declare(strict_types=1);

use App\Domains\Platform\Http\Controllers\HotelController;
use App\Domains\Platform\Http\Controllers\HotelMemberController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform admin (MCS staff)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'verified', 'platform'])
    ->prefix('platform')
    ->name('platform.')
    ->group(function (): void {
        Route::get('hotels', [HotelController::class, 'index'])->name('hotels.index');
        Route::get('hotels/create', [HotelController::class, 'create'])->name('hotels.create');
        Route::post('hotels', [HotelController::class, 'store'])->name('hotels.store');
        Route::get('hotels/{hotel}', [HotelController::class, 'edit'])->name('hotels.edit');
        Route::put('hotels/{hotel}', [HotelController::class, 'update'])->name('hotels.update');

        Route::post('hotels/{hotel}/members', [HotelMemberController::class, 'store'])->name('hotels.members.store');
        Route::delete('hotels/{hotel}/members/{user}', [HotelMemberController::class, 'destroy'])->name('hotels.members.destroy');
    });
