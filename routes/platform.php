<?php

declare(strict_types=1);

use App\Domains\Billing\Http\Controllers\PaymentGatewayController;
use App\Domains\Billing\Http\Controllers\PlatformBillingController;
use App\Domains\Billing\Http\Controllers\PlatformInvoiceController;
use App\Domains\Onboarding\Http\Controllers\PlatformSignupController;
use App\Domains\Platform\Http\Controllers\AiController;
use App\Domains\Platform\Http\Controllers\AnnouncementController;
use App\Domains\Platform\Http\Controllers\AuditLogController;
use App\Domains\Platform\Http\Controllers\FeatureController;
use App\Domains\Platform\Http\Controllers\HotelController;
use App\Domains\Platform\Http\Controllers\HotelMemberController;
use App\Domains\Platform\Http\Controllers\HotelOverviewController;
use App\Domains\Platform\Http\Controllers\MailProviderController;
use App\Domains\Platform\Http\Controllers\OperationsController;
use App\Domains\Platform\Http\Controllers\PilotDashboardController;
use App\Domains\Platform\Http\Controllers\PlatformHomeController;
use App\Domains\Platform\Http\Controllers\PlatformLifecycleController;
use App\Domains\Platform\Http\Controllers\ReadinessController;
use App\Domains\Platform\Http\Controllers\SupportSessionController;
use App\Domains\Platform\Http\Controllers\UserController;
use App\Domains\Platform\Http\Controllers\WhatsAppPlatformController;
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
        Route::middleware('platform.can:hotels.view')->group(function (): void {
            Route::get('/', PlatformHomeController::class)->name('home');
            Route::get('hotels/{hotel}/overview', [HotelOverviewController::class, 'show'])->name('hotels.show');
            Route::get('hotels', [HotelController::class, 'index'])->name('hotels.index');
        });

        Route::middleware('platform.can:billing.view')->group(function (): void {
            Route::get('billing', [PlatformBillingController::class, 'index'])->name('billing.index');
            Route::get('hotels/{hotel}/billing', [PlatformBillingController::class, 'hotel'])->name('hotels.billing');
            Route::get('invoices/{invoice}/pdf', [PlatformInvoiceController::class, 'pdf'])->name('invoices.pdf');
        });

        Route::middleware('platform.can:signups.view')->group(function (): void {
            Route::get('signups', [PlatformSignupController::class, 'index'])->name('signups.index');
        });

        Route::middleware('platform.can:signups.configure')->group(function (): void {
            Route::put('signups/settings', [PlatformSignupController::class, 'settings'])->name('signups.settings');
            Route::post('country-templates', [PlatformSignupController::class, 'storeTemplate'])->name('country-templates.store');
            Route::put('country-templates/{template}', [PlatformSignupController::class, 'updateTemplate'])->name('country-templates.update');
            Route::post('legal/{kind}', [PlatformSignupController::class, 'publishLegal'])->whereIn('kind', ['terms', 'privacy', 'dpa'])->name('legal.publish');
        });

        Route::middleware('platform.can:signups.codes')->group(function (): void {
            Route::post('signups/codes', [PlatformSignupController::class, 'storeCode'])->name('signups.codes.store');
            Route::post('signups/codes/{code}/revoke', [PlatformSignupController::class, 'revokeCode'])->name('signups.codes.revoke');
        });

        Route::middleware('platform.can:hotels.manage')->group(function (): void {
            Route::post('hotels/{hotel}/suspend', [PlatformSignupController::class, 'suspend'])->name('hotels.suspend');
            Route::get('hotels/create', [HotelController::class, 'create'])->name('hotels.create');
            Route::post('hotels', [HotelController::class, 'store'])->name('hotels.store');
            Route::get('hotels/{hotel}', [HotelController::class, 'edit'])->name('hotels.edit');
            Route::put('hotels/{hotel}', [HotelController::class, 'update'])->name('hotels.update');
            Route::post('hotels/{hotel}/members', [HotelMemberController::class, 'store'])->name('hotels.members.store');
            Route::delete('hotels/{hotel}/members/{user}', [HotelMemberController::class, 'destroy'])->name('hotels.members.destroy');
        });

        Route::middleware('platform.can:golive')->group(function (): void {
            Route::post('hotels/{hotel}/golive', [PlatformSignupController::class, 'decideGoLive'])->name('hotels.golive');
        });

        Route::middleware('platform.can:catalog')->group(function (): void {
            Route::post('billing/plans', [PlatformBillingController::class, 'storePlan'])->name('billing.plans.store');
            Route::put('billing/plans/{plan}', [PlatformBillingController::class, 'updatePlan'])->name('billing.plans.update');
            Route::post('billing/packs', [PlatformBillingController::class, 'storePack'])->name('billing.packs.store');
            Route::put('billing/packs/{pack}', [PlatformBillingController::class, 'updatePack'])->name('billing.packs.update');
            Route::put('billing/settings', [PlatformBillingController::class, 'settings'])->name('billing.settings');
            Route::post('billing/gateways', [PaymentGatewayController::class, 'store'])->name('billing.gateways.store');
            Route::put('billing/gateways/{gateway}', [PaymentGatewayController::class, 'update'])->name('billing.gateways.update');
        });

        Route::middleware('platform.can:billing.manage')->group(function (): void {
            Route::post('billing/entities', [PlatformInvoiceController::class, 'storeEntity'])->name('billing.entities.store');
            Route::put('billing/entities/{entity}', [PlatformInvoiceController::class, 'updateEntity'])->name('billing.entities.update');
            Route::put('hotels/{hotel}/billing/details', [PlatformInvoiceController::class, 'details'])->name('hotels.billing.details');
            Route::post('hotels/{hotel}/billing/invoice-now', [PlatformInvoiceController::class, 'issueNow'])->name('hotels.billing.invoice-now');
            Route::post('hotels/{hotel}/billing/delay', [PlatformInvoiceController::class, 'delay'])->name('hotels.billing.delay');
            Route::post('invoices/{invoice}/payments', [PlatformInvoiceController::class, 'payment'])->name('invoices.payments');
            Route::post('invoices/{invoice}/credit-note', [PlatformInvoiceController::class, 'creditNote'])->name('invoices.credit-note');
            Route::post('hotels/{hotel}/billing/subscribe', [PlatformBillingController::class, 'subscribe'])->name('hotels.billing.subscribe');
            Route::post('hotels/{hotel}/billing/trial', [PlatformBillingController::class, 'extendTrial'])->name('hotels.billing.trial');
            Route::post('hotels/{hotel}/billing/adjust', [PlatformBillingController::class, 'adjust'])->name('hotels.billing.adjust');
            Route::post('hotels/{hotel}/billing/packs', [PlatformBillingController::class, 'addPack'])->name('hotels.billing.packs');
        });

        Route::middleware('platform.can:configuration')->group(function (): void {
            Route::post('hotels/{hotel}/close', [PlatformLifecycleController::class, 'close'])->name('hotels.close');
            Route::post('hotels/{hotel}/reopen', [PlatformLifecycleController::class, 'reopen'])->name('hotels.reopen');
            Route::get('mail-providers', [MailProviderController::class, 'index'])->name('mail-providers.index');
            Route::post('mail-providers', [MailProviderController::class, 'store'])->name('mail-providers.store');
            Route::put('mail-providers/{mailProvider}', [MailProviderController::class, 'update'])->name('mail-providers.update');
            Route::delete('mail-providers/{mailProvider}', [MailProviderController::class, 'destroy'])->name('mail-providers.destroy');
            Route::post('mail-providers/{mailProvider}/test', [MailProviderController::class, 'test'])
                ->middleware('throttle:10,1')
                ->name('mail-providers.test');
            Route::post('readiness/{hotel}/override', [ReadinessController::class, 'override'])->name('readiness.override');
            Route::get('ai', [AiController::class, 'index'])->name('ai');
            Route::put('ai/settings', [AiController::class, 'updateSettings'])->name('ai.settings');
            Route::post('ai/providers', [AiController::class, 'storeProvider'])->name('ai.providers.store');
            Route::put('ai/providers/{aiProvider}', [AiController::class, 'updateProvider'])->name('ai.providers.update');
            Route::delete('ai/providers/{aiProvider}', [AiController::class, 'destroyProvider'])->name('ai.providers.destroy');
            Route::post('ai/models', [AiController::class, 'storeModel'])->name('ai.models.store');
            Route::put('ai/models/{aiModel}', [AiController::class, 'updateModel'])->name('ai.models.update');
            Route::delete('ai/models/{aiModel}', [AiController::class, 'destroyModel'])->name('ai.models.destroy');
            Route::post('ai/models/{aiModel}/test', [AiController::class, 'testModel'])->middleware('throttle:10,1')->name('ai.models.test');
            Route::post('ai/models/{aiModel}/evaluate', [AiController::class, 'evaluate'])->middleware('throttle:5,1')->name('ai.models.evaluate');
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
            Route::post('users/{user}/reactivate', [UserController::class, 'reactivate'])->name('users.reactivate');
        });

        Route::middleware('platform.can:notes')->group(function (): void {
            Route::post('hotels/{hotel}/notes', [HotelOverviewController::class, 'storeNote'])->name('hotels.notes.store');
            Route::delete('hotels/{hotel}/notes/{note}', [HotelOverviewController::class, 'destroyNote'])->name('hotels.notes.destroy');
        });

        Route::middleware('platform.can:support')->group(function (): void {
            Route::post('hotels/{hotel}/support', [SupportSessionController::class, 'start'])->middleware('throttle:20,1')->name('hotels.support.start');
            Route::post('support/{session}/enter', [SupportSessionController::class, 'enter'])->name('support.enter');
        });

        Route::middleware('platform.can:announcements')->group(function (): void {
            Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
            Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
            Route::put('announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
            Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');
        });

        Route::middleware('platform.can:configuration')->group(function (): void {
            Route::get('features', [FeatureController::class, 'index'])->name('features.index');
            Route::put('features/{feature}', [FeatureController::class, 'update'])->name('features.update');
        });

        Route::middleware('platform.can:audit.view')->get('audit', [AuditLogController::class, 'index'])->name('audit');
        Route::middleware('platform.can:audit.export')->get('audit/export', [AuditLogController::class, 'export'])->name('audit.export');

        Route::middleware('platform.can:readiness')->group(function (): void {
            Route::get('operations', [OperationsController::class, 'index'])->name('operations');
            Route::post('operations/failed/{uuid}/retry', [OperationsController::class, 'retry'])->name('operations.retry');
            Route::delete('operations/failed/{uuid}', [OperationsController::class, 'forget'])->name('operations.forget');
            Route::get('whatsapp', WhatsAppPlatformController::class)->name('whatsapp');
            Route::get('readiness', [ReadinessController::class, 'index'])->name('readiness');
            Route::get('pilot', PilotDashboardController::class)->name('pilot');
        });
    });
