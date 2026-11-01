<?php

declare(strict_types=1);

use App\Domains\Access\Http\Controllers\AcceptInvitationController;
use App\Domains\Access\Http\Controllers\InvitationController;
use App\Domains\Access\Http\Controllers\RoleController;
use App\Domains\Access\Http\Controllers\StaffController;
use App\Domains\Audit\Http\Controllers\HotelAuditController;
use App\Domains\Availability\Http\Controllers\CalendarController;
use App\Domains\Availability\Http\Controllers\InventoryController;
use App\Domains\Bookings\Http\Controllers\BookingActionController;
use App\Domains\Bookings\Http\Controllers\BookingController;
use App\Domains\Finance\Http\Controllers\TaxRateController;
use App\Domains\Hotel\Http\Controllers\HotelSettingsController;
use App\Domains\Messaging\Http\Controllers\EmailIdentityController;
use App\Domains\Messaging\Http\Controllers\UsageController;
use App\Domains\Rates\Http\Controllers\CategoryRateController;
use App\Domains\Rates\Http\Controllers\RateOverrideController;
use App\Domains\Rates\Http\Controllers\RatesController;
use App\Domains\Rates\Http\Controllers\RateSettingsController;
use App\Domains\Rooms\Http\Controllers\RoomCategoryController;
use App\Domains\Rooms\Http\Controllers\RoomClosureController;
use App\Domains\Rooms\Http\Controllers\RoomController;
use App\Domains\Rooms\Http\Controllers\RoomsController;
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

    Route::get('hotel/rooms', RoomsController::class)->middleware('can:rooms.view')->name('hotel.rooms');
    Route::middleware('can:rooms.manage')->group(function (): void {
        Route::post('hotel/room-categories', [RoomCategoryController::class, 'store'])->name('hotel.room-categories.store');
        Route::put('hotel/room-categories/{roomCategory}', [RoomCategoryController::class, 'update'])->name('hotel.room-categories.update');
        Route::delete('hotel/room-categories/{roomCategory}', [RoomCategoryController::class, 'destroy'])->name('hotel.room-categories.destroy');
        Route::post('hotel/rooms', [RoomController::class, 'store'])->name('hotel.rooms.store');
        Route::post('hotel/rooms/bulk', [RoomController::class, 'bulk'])->name('hotel.rooms.bulk');
        Route::put('hotel/rooms/{room}', [RoomController::class, 'update'])->name('hotel.rooms.update');
        Route::delete('hotel/rooms/{room}', [RoomController::class, 'destroy'])->name('hotel.rooms.destroy');
        Route::post('hotel/rooms/{room}/closures', [RoomClosureController::class, 'store'])->name('hotel.rooms.closures.store');
        Route::delete('hotel/room-closures/{roomClosure}', [RoomClosureController::class, 'destroy'])->name('hotel.room-closures.destroy');
    });

    Route::get('hotel/rates', RatesController::class)->middleware('can:rates.view')->name('hotel.rates');
    Route::middleware('can:rates.manage')->group(function (): void {
        Route::put('hotel/rates/categories/{roomCategory}', [CategoryRateController::class, 'update'])->name('hotel.rates.category');
        Route::put('hotel/rates/settings', [RateSettingsController::class, 'update'])->name('hotel.rates.settings');
        Route::post('hotel/rate-overrides', [RateOverrideController::class, 'store'])->name('hotel.rate-overrides.store');
        Route::put('hotel/rate-overrides/{rateOverride}', [RateOverrideController::class, 'update'])->name('hotel.rate-overrides.update');
        Route::delete('hotel/rate-overrides/{rateOverride}', [RateOverrideController::class, 'destroy'])->name('hotel.rate-overrides.destroy');
    });

    Route::get('hotel/calendar', CalendarController::class)->middleware('can:availability.view')->name('hotel.calendar');
    Route::middleware('can:availability.manage')->group(function (): void {
        Route::post('hotel/calendar/stop-sell', [InventoryController::class, 'stopSell'])->name('hotel.calendar.stop-sell');
        Route::post('hotel/calendar/hold-back', [InventoryController::class, 'holdBack'])->name('hotel.calendar.hold-back');
        Route::post('hotel/holds', [InventoryController::class, 'placeHold'])->name('hotel.holds.store');
        Route::post('hotel/holds/{inventoryHold}/extend', [InventoryController::class, 'extendHold'])->name('hotel.holds.extend');
        Route::delete('hotel/holds/{inventoryHold}', [InventoryController::class, 'releaseHold'])->name('hotel.holds.release');
    });

    Route::get('hotel/bookings', [BookingController::class, 'index'])->middleware('can:bookings.view')->name('hotel.bookings.index');
    Route::get('hotel/bookings/new', [BookingController::class, 'create'])->middleware('can:bookings.create')->name('hotel.bookings.create');
    Route::post('hotel/bookings', [BookingController::class, 'store'])->middleware('can:bookings.create')->name('hotel.bookings.store');
    Route::get('hotel/bookings/{booking}', [BookingController::class, 'show'])->middleware('can:bookings.view')->name('hotel.bookings.show');
    Route::middleware('can:bookings.manage')->group(function (): void {
        Route::post('hotel/bookings/{booking}/confirm', [BookingActionController::class, 'confirm'])->name('hotel.bookings.confirm');
        Route::post('hotel/bookings/{booking}/cancel', [BookingActionController::class, 'cancel'])->name('hotel.bookings.cancel');
        Route::post('hotel/bookings/{booking}/no-show', [BookingActionController::class, 'noShow'])->name('hotel.bookings.no-show');
        Route::put('hotel/bookings/{booking}', [BookingActionController::class, 'update'])->name('hotel.bookings.update');
    });
    Route::middleware('can:bookings.frontdesk')->group(function (): void {
        Route::put('hotel/bookings/{booking}/rooms/{position}', [BookingActionController::class, 'assignRoom'])->whereNumber('position')->name('hotel.bookings.rooms.assign');
        Route::post('hotel/bookings/{booking}/check-in', [BookingActionController::class, 'checkIn'])->name('hotel.bookings.check-in');
        Route::post('hotel/bookings/{booking}/check-out', [BookingActionController::class, 'checkOut'])->name('hotel.bookings.check-out');
    });

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
