<?php

declare(strict_types=1);

use App\Domains\Billing\Http\Controllers\PaymentReturnController;
use App\Domains\Events\Http\Controllers\PublicQuoteController;
use App\Domains\Identity\Http\Controllers\LocaleController;
use App\Domains\Onboarding\Http\Controllers\LegalController;
use App\Domains\Onboarding\Http\Controllers\SignupController;
use App\Domains\Platform\Http\Controllers\AccountHotelsController;
use App\Domains\Platform\Http\Controllers\HealthController;
use App\Domains\Restaurant\Http\Controllers\PublicMenuController;
use App\Domains\Tenancy\Http\Controllers\SwitchHotelController;
use App\Domains\WhatsApp\Http\Controllers\WebhookController as WhatsAppWebhookController;
use App\Http\Controllers\CspReportController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

// Hotel owners sign up; legal texts are public.
Route::middleware('guest')->group(function (): void {
    Route::get('signup', [SignupController::class, 'show'])->name('signup');
    Route::post('signup', [SignupController::class, 'store'])->middleware('throttle:5,60')->name('signup.store');
});
Route::get('legal/{kind}', [LegalController::class, 'show'])->whereIn('kind', ['terms', 'privacy', 'dpa'])->name('legal.show');
Route::middleware('auth')->group(function (): void {
    Route::get('legal-accept', [LegalController::class, 'acceptPage'])->name('legal.accept');
    Route::post('legal-accept', [LegalController::class, 'accept'])->name('legal.accept.store');
});

// Inside a support session (platform user in a hotel's account).
Route::middleware('auth')->group(function (): void {
    Route::post('support/extend', [App\Domains\Platform\Http\Controllers\SupportSessionController::class, 'extend'])->name('support.extend');
    Route::post('support/end', [App\Domains\Platform\Http\Controllers\SupportSessionController::class, 'end'])->name('support.end');
});

Route::get('/', fn () => auth()->check() ? to_route('dashboard') : to_route('login'))->name('home');

Route::post('locale', LocaleController::class)->middleware('throttle:30,1')->name('locale.update');

Route::get('health', HealthController::class)->middleware('throttle:30,1')->name('health');

// The client's private quote page (the link is the key).
Route::middleware('throttle:30,1')->group(function (): void {
    Route::get('q/{token}', [PublicQuoteController::class, 'show'])->name('quotes.public');
    Route::post('q/{token}', [PublicQuoteController::class, 'accept'])->name('quotes.public.accept');
});

// Public menu pages (no login, no personal data), linked from WhatsApp and QR codes.
Route::middleware('throttle:120,1')->group(function (): void {
    Route::get('m/{hotel}/{outlet}', [PublicMenuController::class, 'show'])->name('menu.public');
    Route::get('m/{hotel}/{outlet}/photos/{item}', [PublicMenuController::class, 'photo'])->whereNumber('item')->name('menu.public.photo');
});

Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->middleware('throttle:60,1')->name('webhooks.whatsapp.verify');
Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:600,1')
    ->name('webhooks.whatsapp.receive');

// Online payments: the payer comes back here; providers post notifications.
Route::get('payments/return/{checkout}', [PaymentReturnController::class, 'back'])->middleware('throttle:30,1')->name('payments.return');
Route::post('webhooks/payments/{driver}', [PaymentReturnController::class, 'notify'])
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.payments');

Route::post('csp-report', CspReportController::class)
    ->middleware('throttle:60,1')
    ->withoutMiddleware(PreventRequestForgery::class)
    ->name('csp.report');

Route::middleware(['auth', 'verified'])->group(function () {
    // The owner's hotels: add, close, export, reopen (works for closed hotels too).
    Route::get('account/hotels', [AccountHotelsController::class, 'index'])->name('account.hotels');
    Route::post('account/hotels', [AccountHotelsController::class, 'store'])->middleware('throttle:5,60')->name('account.hotels.store');
    Route::post('account/hotels/{hotel}/close', [AccountHotelsController::class, 'close'])->middleware('throttle:5,10')->name('account.hotels.close');
    Route::post('account/hotels/{hotel}/reopen', [AccountHotelsController::class, 'reopen'])->name('account.hotels.reopen');
    Route::get('account/hotels/{hotel}/export', [AccountHotelsController::class, 'export'])->name('account.hotels.export');
    // Hotel staff who handle bookings start on the Today screen.
    Route::get('dashboard', fn () => request()->user()?->can('bookings.view') === true
        ? to_route('hotel.today')
        : Inertia\Inertia::render('dashboard'))->name('dashboard');

    Route::post('hotels/{hotel}/switch', SwitchHotelController::class)->name('hotels.switch');
});

require __DIR__.'/settings.php';
require __DIR__.'/platform.php';
require __DIR__.'/hotel.php';
