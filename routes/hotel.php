<?php

declare(strict_types=1);

use App\Domains\Access\Http\Controllers\AcceptInvitationController;
use App\Domains\Access\Http\Controllers\InvitationController;
use App\Domains\Access\Http\Controllers\RoleController;
use App\Domains\Access\Http\Controllers\StaffController;
use App\Domains\Audit\Http\Controllers\HotelAuditController;
use App\Domains\Finance\Http\Controllers\TaxRateController;
use App\Domains\Hotel\Http\Controllers\HotelSettingsController;
use App\Domains\Messaging\Http\Controllers\EmailIdentityController;
use App\Domains\Messaging\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Hotel workspace (requires a current hotel)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'verified', 'hotel'])->group(function (): void {
    Route::get('staff', [StaffController::class, 'index'])->middleware('can:staff.view')->name('staff.index');

    Route::middleware('can:staff.manage')->group(function (): void {
        Route::put('staff/{member}/role', [StaffController::class, 'updateRole'])->name('staff.role');
        Route::post('staff/{member}/suspend', [StaffController::class, 'suspend'])->name('staff.suspend');
        Route::post('staff/{member}/reactivate', [StaffController::class, 'reactivate'])->name('staff.reactivate');
        Route::delete('staff/{member}', [StaffController::class, 'destroy'])->name('staff.destroy');
        Route::post('staff/{member}/primary-owner', [StaffController::class, 'makePrimaryOwner'])
            ->middleware('password.confirm')
            ->name('staff.primary-owner');
    });

    Route::middleware('can:staff.invite')->group(function (): void {
        Route::post('staff/invitations', [InvitationController::class, 'store'])->name('staff.invitations.store');
        Route::post('staff/invitations/{invitation}/resend', [InvitationController::class, 'resend'])->name('staff.invitations.resend');
        Route::delete('staff/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('staff.invitations.destroy');
    });

    Route::get('hotel/settings', [HotelSettingsController::class, 'edit'])->middleware('can:hotel.settings.view')->name('hotel.settings.edit');
    Route::put('hotel/settings', [HotelSettingsController::class, 'update'])->middleware('can:hotel.settings.manage')->name('hotel.settings.update');

    Route::get('hotel/audit', HotelAuditController::class)->middleware('can:audit.view')->name('hotel.audit');

    Route::put('hotel/settings/email', [EmailIdentityController::class, 'update'])->middleware('can:hotel.settings.manage')->name('hotel.email.update');
    Route::post('hotel/settings/email/resend', [EmailIdentityController::class, 'resend'])
        ->middleware(['can:hotel.settings.manage', 'throttle:5,1'])
        ->name('hotel.email.resend');
    Route::get('hotel/usage', UsageController::class)->middleware('can:usage.view')->name('hotel.usage');

    Route::get('hotel/taxes', [TaxRateController::class, 'index'])->middleware('can:taxes.view')->name('hotel.taxes.index');
    Route::middleware('can:taxes.manage')->group(function (): void {
        Route::post('hotel/taxes', [TaxRateController::class, 'store'])->name('hotel.taxes.store');
        Route::put('hotel/taxes/{taxRate}', [TaxRateController::class, 'update'])->name('hotel.taxes.update');
        Route::delete('hotel/taxes/{taxRate}', [TaxRateController::class, 'destroy'])->name('hotel.taxes.destroy');
    });

    Route::middleware('can:roles.manage')->group(function (): void {
        Route::get('staff/roles', [RoleController::class, 'index'])->name('staff.roles.index');
        Route::post('staff/roles', [RoleController::class, 'store'])->name('staff.roles.store');
        Route::put('staff/roles/{role}', [RoleController::class, 'update'])->whereNumber('role')->name('staff.roles.update');
        Route::delete('staff/roles/{role}', [RoleController::class, 'destroy'])->whereNumber('role')->name('staff.roles.destroy');
    });
});

/*
|--------------------------------------------------------------------------
| Invitation links (public)
|--------------------------------------------------------------------------
*/

Route::get('invitations/{token}', [AcceptInvitationController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('invitations.show');
Route::post('invitations/{token}', [AcceptInvitationController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('invitations.accept');

Route::get('email/reply-to/{token}', [EmailIdentityController::class, 'verify'])
    ->middleware('throttle:30,1')
    ->name('email.reply-to.verify');
