<?php

declare(strict_types=1);

use App\Domains\Billing\Http\Controllers\PaymentReturnController;
use App\Domains\Events\Http\Controllers\PublicQuoteController;
use App\Domains\Help\Http\Controllers\HelpController;
use App\Domains\Identity\Http\Controllers\LocaleController;
use App\Domains\Onboarding\Http\Controllers\LegalController;
use App\Domains\Onboarding\Http\Controllers\SignupController;
use App\Domains\Platform\Http\Controllers\AccountHotelsController;
use App\Domains\Platform\Http\Controllers\HealthController;
use App\Domains\Restaurant\Http\Controllers\PublicMenuController;
use App\Domains\Site\Http\Controllers\SiteController;
use App\Domains\Status\Http\Controllers\StatusController;
use App\Domains\Support\Http\Controllers\SupportAttachmentController;
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

// Public website (S17): French at /, English at /en.
Route::middleware('throttle:120,1')->group(function (): void {
    Route::get('/', [SiteController::class, 'home'])->name('home');
    Route::get('en', [SiteController::class, 'home'])->name('site.home.en');
    Route::get('{page}', [SiteController::class, 'page'])->whereIn('page', ['features', 'pricing', 'countries', 'faq', 'demo'])->name('site.page');
    Route::get('en/{page}', [SiteController::class, 'page'])->whereIn('page', ['features', 'pricing', 'countries', 'faq', 'demo'])->name('site.page.en');
    Route::get('sitemap.xml', [SiteController::class, 'sitemap'])->name('site.sitemap');
    Route::get('brand/logo', [SiteController::class, 'logo'])->name('brand.logo');
});
// Help centre (S17): public, French at /help and English at /en/help.
Route::middleware('throttle:120,1')->group(function (): void {
    Route::get('help', [HelpController::class, 'index'])->name('help.index');
    Route::get('en/help', [HelpController::class, 'index'])->name('help.index.en');
    Route::get('help/media/{file}', [HelpController::class, 'media'])->name('help.media');
    Route::get('help/{slug}', [HelpController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('help.show');
    Route::get('en/help/{slug}', [HelpController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('help.show.en');
});
Route::post('help/{slug}/feedback', [HelpController::class, 'feedback'])->middleware('throttle:20,60')->name('help.feedback');
Route::post('en/help/{slug}/feedback', [HelpController::class, 'feedback'])->middleware('throttle:20,60')->name('help.feedback.en');
Route::middleware(['auth', 'throttle:60,1'])->get('help-panel', [HelpController::class, 'panel'])->name('help.panel');
Route::middleware(['auth', 'throttle:120,1'])->get('support/attachments/{ticket}/{file}', SupportAttachmentController::class)->whereNumber('ticket')->name('support.attachment');

// Status page (S17): French at /status, English at /en/status.
Route::middleware('throttle:120,1')->group(function (): void {
    Route::get('status', [StatusController::class, 'index'])->name('status');
    Route::get('en/status', [StatusController::class, 'index'])->name('status.en');
    Route::get('status/confirm/{token}', [StatusController::class, 'confirm'])->name('status.confirm');
    Route::get('en/status/confirm/{token}', [StatusController::class, 'confirm'])->name('status.confirm.en');
    Route::get('status/unsubscribe/{token}', [StatusController::class, 'unsubscribe'])->name('status.unsubscribe');
    Route::get('en/status/unsubscribe/{token}', [StatusController::class, 'unsubscribe'])->name('status.unsubscribe.en');
});
Route::post('status/subscribe', [StatusController::class, 'subscribe'])->middleware('throttle:5,60')->name('status.subscribe');
Route::post('en/status/subscribe', [StatusController::class, 'subscribe'])->middleware('throttle:5,60')->name('status.subscribe.en');

Route::post('demo', [SiteController::class, 'demo'])->middleware('throttle:5,60')->name('site.demo');
Route::post('en/demo', [SiteController::class, 'demo'])->middleware('throttle:5,60')->name('site.demo.en');

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
    // Platform staff without a hotel start on the platform home.
    Route::get('dashboard', fn () => match (true) {
        request()->user()?->can('bookings.view') === true => to_route('hotel.today'),
        request()->user()?->isPlatformStaff() === true => to_route('platform.home'),
        default => Inertia\Inertia::render('dashboard'),
    })->name('dashboard');

    Route::post('hotels/{hotel}/switch', SwitchHotelController::class)->name('hotels.switch');
});

require __DIR__.'/settings.php';
require __DIR__.'/platform.php';
require __DIR__.'/hotel.php';
