<?php

declare(strict_types=1);

use App\Domains\Identity\Http\Controllers\LocaleController;
use App\Domains\Platform\Http\Controllers\HealthController;
use App\Domains\Tenancy\Http\Controllers\SwitchHotelController;
use App\Domains\WhatsApp\Http\Controllers\WebhookController as WhatsAppWebhookController;
use App\Http\Controllers\CspReportController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? to_route('dashboard') : to_route('login'))->name('home');

Route::post('locale', LocaleController::class)->middleware('throttle:30,1')->name('locale.update');

Route::get('health', HealthController::class)->middleware('throttle:30,1')->name('health');

Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp.verify');
Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->middleware('throttle:600,1')
    ->name('webhooks.whatsapp.receive');

Route::post('csp-report', CspReportController::class)
    ->middleware('throttle:60,1')
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->name('csp.report');

Route::middleware(['auth', 'verified'])->group(function () {
    // Hotel staff who handle bookings start on the Today screen.
    Route::get('dashboard', fn () => request()->user()?->can('bookings.view') === true
        ? to_route('hotel.today')
        : Inertia\Inertia::render('dashboard'))->name('dashboard');

    Route::post('hotels/{hotel}/switch', SwitchHotelController::class)->name('hotels.switch');
});

require __DIR__.'/settings.php';
require __DIR__.'/platform.php';
require __DIR__.'/hotel.php';
