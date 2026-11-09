<?php

declare(strict_types=1);

use App\Domains\Access\Http\Controllers\AcceptInvitationController;
use App\Domains\Access\Http\Controllers\InvitationController;
use App\Domains\Access\Http\Controllers\RoleController;
use App\Domains\Access\Http\Controllers\StaffController;
use App\Domains\Assistant\Http\Controllers\AssistantSettingsController;
use App\Domains\Assistant\Http\Controllers\KnowledgeController;
use App\Domains\Assistant\Http\Controllers\PracticeController;
use App\Domains\Audit\Http\Controllers\HotelAuditController;
use App\Domains\Availability\Http\Controllers\CalendarController;
use App\Domains\Availability\Http\Controllers\InventoryController;
use App\Domains\Billing\Http\Controllers\BillingController;
use App\Domains\Billing\Http\Controllers\HotelPaymentController;
use App\Domains\BookingRequests\Http\Controllers\BookingRequestController;
use App\Domains\Bookings\Http\Controllers\BookingActionController;
use App\Domains\Bookings\Http\Controllers\BookingController;
use App\Domains\Bookings\Http\Controllers\BookingExportController;
use App\Domains\Bookings\Http\Controllers\TodayController;
use App\Domains\Conversations\Http\Controllers\InboxController;
use App\Domains\Demo\Http\Controllers\HotelDemoController;
use App\Domains\Events\Http\Controllers\EventAccountController;
use App\Domains\Events\Http\Controllers\EventController;
use App\Domains\Events\Http\Controllers\EventQuoteController;
use App\Domains\Events\Http\Controllers\EventSetupController;
use App\Domains\Events\Http\Controllers\EventSheetController;
use App\Domains\Events\Http\Controllers\RoomBlockController;
use App\Domains\Finance\Http\Controllers\TaxRateController;
use App\Domains\Folio\Http\Controllers\FolioController;
use App\Domains\GoLive\Http\Controllers\GoLiveController;
use App\Domains\Hotel\Http\Controllers\HotelSettingsController;
use App\Domains\Messaging\Http\Controllers\EmailIdentityController;
use App\Domains\Messaging\Http\Controllers\UsageController;
use App\Domains\Onboarding\Http\Controllers\SetupController;
use App\Domains\Orders\Http\Controllers\OrderBoardController;
use App\Domains\Orders\Http\Controllers\StayPhoneController;
use App\Domains\Privacy\Http\Controllers\GuestDataController;
use App\Domains\Rates\Http\Controllers\CategoryRateController;
use App\Domains\Rates\Http\Controllers\RateOverrideController;
use App\Domains\Rates\Http\Controllers\RatesController;
use App\Domains\Rates\Http\Controllers\RateSettingsController;
use App\Domains\Restaurant\Http\Controllers\MenuController;
use App\Domains\Restaurant\Http\Controllers\OutletController;
use App\Domains\Restaurant\Http\Controllers\RestaurantController;
use App\Domains\Restaurant\Http\Controllers\TableBookingController;
use App\Domains\Rooms\Http\Controllers\RoomCategoryController;
use App\Domains\Rooms\Http\Controllers\RoomClosureController;
use App\Domains\Rooms\Http\Controllers\RoomController;
use App\Domains\Rooms\Http\Controllers\RoomsController;
use App\Domains\StaffAlerts\Http\Controllers\AlertSettingsController;
use App\Domains\Support\Http\Controllers\HotelSupportController;
use App\Domains\WhatsApp\Http\Controllers\TemplateController;
use App\Domains\WhatsApp\Http\Controllers\WhatsAppConnectionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Hotel workspace (requires a current hotel)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'verified', 'hotel', 'reduced'])->group(function (): void {
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

    Route::middleware('can:whatsapp.connect')->group(function (): void {
        Route::get('hotel/whatsapp', [WhatsAppConnectionController::class, 'show'])->name('hotel.whatsapp');
        Route::post('hotel/whatsapp/connect', [WhatsAppConnectionController::class, 'connect'])->middleware('throttle:10,1')->name('hotel.whatsapp.connect');
        Route::post('hotel/whatsapp/check', [WhatsAppConnectionController::class, 'check'])->middleware('throttle:10,1')->name('hotel.whatsapp.check');
        Route::get('hotel/whatsapp/templates', [TemplateController::class, 'index'])->name('hotel.whatsapp.templates');
        Route::post('hotel/whatsapp/templates', [TemplateController::class, 'store'])->middleware('throttle:20,1')->name('hotel.whatsapp.templates.store');
        Route::post('hotel/whatsapp/templates/{template}/submit', [TemplateController::class, 'submit'])->middleware('throttle:20,1')->name('hotel.whatsapp.templates.submit');
        Route::delete('hotel/whatsapp/templates/{template}', [TemplateController::class, 'destroy'])->name('hotel.whatsapp.templates.destroy');
        Route::delete('hotel/whatsapp', [WhatsAppConnectionController::class, 'disconnect'])->middleware('password.confirm')->name('hotel.whatsapp.disconnect');
    });

    Route::middleware('can:conversations.view')->group(function (): void {
        Route::get('hotel/inbox', [InboxController::class, 'index'])->name('hotel.inbox');
        Route::get('hotel/inbox/media/{message}', [InboxController::class, 'media'])->name('hotel.inbox.media');
        Route::get('hotel/inbox/{conversation}', [InboxController::class, 'show'])->name('hotel.inbox.show');
    });
    Route::post('hotel/inbox/{conversation}/reply', [InboxController::class, 'reply'])
        ->middleware(['can:conversations.reply', 'throttle:60,1'])
        ->name('hotel.inbox.reply');
    Route::middleware('can:conversations.reply')->group(function (): void {
        Route::put('hotel/inbox/{conversation}/assign', [InboxController::class, 'assign'])->name('hotel.inbox.assign');
        Route::post('hotel/inbox/{conversation}/notes', [InboxController::class, 'note'])->middleware('throttle:60,1')->name('hotel.inbox.notes');
        Route::post('hotel/inbox/{conversation}/translate', [InboxController::class, 'translateDraft'])->middleware('throttle:30,1')->name('hotel.inbox.translate');
    });
    Route::middleware(['can:conversations.reply', 'throttle:60,1'])->group(function (): void {
        Route::post('hotel/inbox/drafts/{message}/send', [InboxController::class, 'sendDraft'])->name('hotel.inbox.drafts.send');
        Route::post('hotel/inbox/drafts/{message}/discard', [InboxController::class, 'discardDraft'])->name('hotel.inbox.drafts.discard');
    });
    Route::post('hotel/inbox/messages/{message}/translate', [InboxController::class, 'translateMessage'])
        ->middleware(['can:conversations.view', 'throttle:30,1'])
        ->name('hotel.inbox.messages.translate');
    Route::put('hotel/inbox/{conversation}/mode', [InboxController::class, 'mode'])
        ->middleware('can:conversations.reply')
        ->name('hotel.inbox.mode');
    Route::post('hotel/inbox/{conversation}/template', [InboxController::class, 'sendTemplate'])
        ->middleware(['can:conversations.reply', 'throttle:60,1'])
        ->name('hotel.inbox.template');

    Route::middleware('can:assistant.manage')->group(function (): void {
        Route::get('hotel/go-live', GoLiveController::class)->name('hotel.go-live');
        Route::get('hotel/assistant', [AssistantSettingsController::class, 'show'])->name('hotel.assistant');
        Route::put('hotel/assistant', [AssistantSettingsController::class, 'update'])->name('hotel.assistant.update');
        Route::get('hotel/assistant/knowledge', [KnowledgeController::class, 'show'])->name('hotel.assistant.knowledge');
        Route::put('hotel/assistant/knowledge', [KnowledgeController::class, 'update'])->name('hotel.assistant.knowledge.update');
        Route::post('hotel/assistant/knowledge/publish', [KnowledgeController::class, 'publish'])->name('hotel.assistant.knowledge.publish');
        Route::get('hotel/assistant/practice', [PracticeController::class, 'show'])->name('hotel.assistant.practice');
        Route::post('hotel/assistant/practice', [PracticeController::class, 'run'])->middleware('throttle:30,1')->name('hotel.assistant.practice.run');
    });

    Route::middleware(['can:booking_requests.decide', 'throttle:60,1'])->group(function (): void {
        Route::post('hotel/booking-requests/{bookingRequest}/approve', [BookingRequestController::class, 'approve'])->name('hotel.booking-requests.approve');
        Route::post('hotel/booking-requests/{bookingRequest}/decline', [BookingRequestController::class, 'decline'])->name('hotel.booking-requests.decline');
    });

    Route::get('hotel/my-alerts', [AlertSettingsController::class, 'show'])->name('hotel.my-alerts');
    Route::put('hotel/my-alerts', [AlertSettingsController::class, 'update'])->name('hotel.my-alerts.update');
    Route::post('hotel/my-alerts/test', [AlertSettingsController::class, 'test'])->middleware('throttle:5,1')->name('hotel.my-alerts.test');

    Route::middleware(['module:restaurant', 'can:restaurant.view'])->get('hotel/restaurant', RestaurantController::class)->name('hotel.restaurant');
    Route::middleware(['module:restaurant', 'can:restaurant.bookings', 'throttle:120,1'])->group(function (): void {
        Route::post('hotel/restaurant/bookings', [TableBookingController::class, 'store'])->name('hotel.restaurant.bookings.store');
        Route::put('hotel/restaurant/bookings/{tableBooking}', [TableBookingController::class, 'update'])->name('hotel.restaurant.bookings.update');
        Route::post('hotel/restaurant/bookings/{tableBooking}/status', [TableBookingController::class, 'status'])->name('hotel.restaurant.bookings.status');
    });
    Route::middleware(['module:ordering', 'can:restaurant.orders', 'throttle:240,1'])->group(function (): void {
        Route::get('hotel/restaurant/orders', [OrderBoardController::class, 'index'])->name('hotel.restaurant.orders');
        Route::post('hotel/restaurant/orders', [OrderBoardController::class, 'store'])->name('hotel.restaurant.orders.store');
        Route::post('hotel/restaurant/orders/{order}/status', [OrderBoardController::class, 'status'])->name('hotel.restaurant.orders.status');
    });
    Route::middleware('can:folio.view')->get('hotel/events/{event}/statement', [EventAccountController::class, 'statement'])->name('hotel.events.statement');
    Route::middleware(['can:folio.charge', 'throttle:120,1'])->post('hotel/events/{event}/charges', [EventAccountController::class, 'charge'])->name('hotel.events.charges.store');
    Route::middleware(['can:folio.payments', 'throttle:120,1'])->post('hotel/events/{event}/payments', [EventAccountController::class, 'pay'])->name('hotel.events.payments.store');
    Route::middleware('can:folio.view')->get('hotel/bookings/{booking}/statement', [FolioController::class, 'statement'])->name('hotel.bookings.statement');
    Route::middleware(['can:folio.charge', 'throttle:120,1'])->post('hotel/bookings/{booking}/charges', [FolioController::class, 'charge'])->name('hotel.bookings.charges.store');
    Route::middleware(['can:folio.payments', 'throttle:120,1'])->post('hotel/bookings/{booking}/payments', [FolioController::class, 'pay'])->name('hotel.bookings.payments.store');
    Route::middleware('throttle:60,1')->post('hotel/folio-lines/{line}/correct', [FolioController::class, 'correct'])->name('hotel.folio-lines.correct');
    Route::middleware('can:bookings.frontdesk')->group(function (): void {
        Route::post('hotel/bookings/{booking}/phones', [StayPhoneController::class, 'store'])->name('hotel.bookings.phones.store');
        Route::delete('hotel/stay-phones/{stayPhone}', [StayPhoneController::class, 'destroy'])->name('hotel.stay-phones.destroy');
    });
    Route::middleware(['module:restaurant', 'can:restaurant.menu', 'throttle:120,1'])->group(function (): void {
        Route::get('hotel/restaurant/menu', [MenuController::class, 'index'])->name('hotel.restaurant.menu');
        Route::get('hotel/restaurant/menu/{outlet}/qr', [MenuController::class, 'qr'])->name('hotel.restaurant.menu.qr');
        Route::post('hotel/restaurant/menu/sections', [MenuController::class, 'storeSection'])->name('hotel.restaurant.menu.sections.store');
        Route::put('hotel/restaurant/menu/sections/{section}', [MenuController::class, 'updateSection'])->name('hotel.restaurant.menu.sections.update');
        Route::delete('hotel/restaurant/menu/sections/{section}', [MenuController::class, 'destroySection'])->name('hotel.restaurant.menu.sections.destroy');
        Route::post('hotel/restaurant/menu/items', [MenuController::class, 'storeItem'])->name('hotel.restaurant.menu.items.store');
        Route::put('hotel/restaurant/menu/items/{item}', [MenuController::class, 'updateItem'])->name('hotel.restaurant.menu.items.update');
        Route::delete('hotel/restaurant/menu/items/{item}', [MenuController::class, 'destroyItem'])->name('hotel.restaurant.menu.items.destroy');
        Route::put('hotel/restaurant/menu/items/{item}/today', [MenuController::class, 'today'])->name('hotel.restaurant.menu.items.today');
        Route::post('hotel/restaurant/menu/items/{item}/photo', [MenuController::class, 'photo'])->name('hotel.restaurant.menu.items.photo');
        Route::delete('hotel/restaurant/menu/items/{item}/photo', [MenuController::class, 'removePhoto'])->name('hotel.restaurant.menu.items.photo.destroy');
    });
    Route::middleware(['module:restaurant', 'can:restaurant.manage'])->group(function (): void {
        Route::get('hotel/restaurant/outlets', [OutletController::class, 'index'])->name('hotel.restaurant.outlets');
        Route::put('hotel/restaurant/room-charges', [OutletController::class, 'roomCharges'])->name('hotel.restaurant.room-charges');
        Route::post('hotel/restaurant/outlets', [OutletController::class, 'store'])->name('hotel.restaurant.outlets.store');
        Route::put('hotel/restaurant/outlets/{outlet}', [OutletController::class, 'update'])->name('hotel.restaurant.outlets.update');
        Route::put('hotel/restaurant/outlets/{outlet}/periods', [OutletController::class, 'periods'])->name('hotel.restaurant.outlets.periods');
        Route::post('hotel/restaurant/outlets/{outlet}/closures', [OutletController::class, 'close'])->name('hotel.restaurant.outlets.close');
        Route::delete('hotel/restaurant/closures/{closure}', [OutletController::class, 'reopen'])->name('hotel.restaurant.closures.destroy');
    });

    Route::middleware(['module:events', 'can:events.view'])->group(function (): void {
        Route::get('hotel/events', [EventController::class, 'index'])->name('hotel.events');
        Route::get('hotel/events/{event}', [EventController::class, 'show'])->whereUlid('event')->name('hotel.events.show');
        Route::get('hotel/events/{event}/sheet', [EventSheetController::class, 'print'])->name('hotel.events.sheet');
    });
    Route::middleware(['can:bookings.create', 'throttle:120,1'])->post('hotel/room-blocks/{block}/book', [RoomBlockController::class, 'book'])->name('hotel.room-blocks.book');
    Route::middleware(['module:events', 'can:events.manage', 'throttle:120,1'])->group(function (): void {
        Route::post('hotel/events', [EventController::class, 'store'])->name('hotel.events.store');
        Route::put('hotel/events/{event}', [EventController::class, 'update'])->whereUlid('event')->name('hotel.events.update');
        Route::post('hotel/events/{event}/option', [EventController::class, 'option'])->name('hotel.events.option');
        Route::post('hotel/events/{event}/confirm', [EventController::class, 'confirm'])->name('hotel.events.confirm');
        Route::post('hotel/events/{event}/close', [EventController::class, 'close'])->name('hotel.events.close');
        Route::get('hotel/events/setup', [EventSetupController::class, 'index'])->name('hotel.events.setup');
        Route::put('hotel/events/settings', [EventQuoteController::class, 'settings'])->name('hotel.events.settings');
        Route::post('hotel/events/{event}/quotes', [EventQuoteController::class, 'store'])->name('hotel.events.quotes.store');
        Route::post('hotel/event-quotes/{quote}/send', [EventQuoteController::class, 'send'])->name('hotel.event-quotes.send');
        Route::post('hotel/event-quotes/{quote}/accept', [EventQuoteController::class, 'accept'])->name('hotel.event-quotes.accept');
        Route::post('hotel/events/{event}/sheet', [EventSheetController::class, 'save'])->name('hotel.events.sheet.save');
        Route::post('hotel/events/{event}/room-blocks', [RoomBlockController::class, 'store'])->name('hotel.events.room-blocks.store');
        Route::post('hotel/room-blocks/{block}/release', [RoomBlockController::class, 'release'])->name('hotel.room-blocks.release');
        Route::post('hotel/events/spaces', [EventSetupController::class, 'storeSpace'])->name('hotel.events.spaces.store');
        Route::put('hotel/events/spaces/{space}', [EventSetupController::class, 'updateSpace'])->name('hotel.events.spaces.update');
        Route::post('hotel/events/blocks', [EventSetupController::class, 'storeBlock'])->name('hotel.events.blocks.store');
        Route::put('hotel/events/blocks/{block}', [EventSetupController::class, 'updateBlock'])->name('hotel.events.blocks.update');
        Route::put('hotel/events/prices', [EventSetupController::class, 'savePrices'])->name('hotel.events.prices');
        Route::post('hotel/events/packages', [EventSetupController::class, 'storePackage'])->name('hotel.events.packages.store');
        Route::put('hotel/events/packages/{package}', [EventSetupController::class, 'updatePackage'])->name('hotel.events.packages.update');
    });

    Route::middleware('can:billing.view')->get('hotel/billing', BillingController::class)->name('hotel.billing');
    // Demo hotels (S17): scenarios, "your hotel", reset.
    Route::middleware('can:bookings.view')->group(function (): void {
        Route::get('hotel/demo', [HotelDemoController::class, 'show'])->name('hotel.demo');
        Route::post('hotel/demo/play', [HotelDemoController::class, 'play'])->middleware('throttle:30,1')->name('hotel.demo.play');
        Route::get('hotel/demo/feed', [HotelDemoController::class, 'feed'])->middleware('throttle:120,1')->name('hotel.demo.feed');
        Route::post('hotel/demo/personalise', [HotelDemoController::class, 'personalise'])->middleware('throttle:10,10')->name('hotel.demo.personalise');
        Route::post('hotel/demo/reset', [HotelDemoController::class, 'reset'])->middleware('throttle:10,10')->name('hotel.demo.reset');
    });

    Route::middleware('can:support.contact')->group(function (): void {
        Route::get('hotel/requests', [HotelSupportController::class, 'index'])->name('hotel.requests');
        Route::post('hotel/requests', [HotelSupportController::class, 'store'])->middleware('throttle:10,60')->name('hotel.requests.store');
        Route::get('hotel/requests/{ticket}', [HotelSupportController::class, 'show'])->whereNumber('ticket')->name('hotel.requests.show');
        Route::post('hotel/requests/{ticket}/reply', [HotelSupportController::class, 'reply'])->whereNumber('ticket')->middleware('throttle:30,10')->name('hotel.requests.reply');
        Route::post('hotel/requests/{ticket}/rate', [HotelSupportController::class, 'rate'])->whereNumber('ticket')->name('hotel.requests.rate');
    });
    Route::middleware('can:hotel.settings.manage')->group(function (): void {
        Route::get('hotel/privacy', [GuestDataController::class, 'index'])->name('hotel.privacy');
        Route::get('hotel/privacy/guests/{guest}/export', [GuestDataController::class, 'export'])->name('hotel.privacy.export');
        Route::post('hotel/privacy/guests/{guest}/anonymise', [GuestDataController::class, 'anonymise'])->middleware('throttle:10,1')->name('hotel.privacy.anonymise');
    });
    Route::middleware('can:billing.view')->get('hotel/billing/invoices/{invoice}', [BillingController::class, 'invoice'])->name('hotel.billing.invoice');
    Route::middleware(['can:billing.manage', 'throttle:20,1'])->group(function (): void {
        Route::post('hotel/billing/invoices/{invoice}/pay', [HotelPaymentController::class, 'payInvoice'])->name('hotel.billing.invoices.pay');
        Route::post('hotel/billing/packs/{pack}/buy', [HotelPaymentController::class, 'buyPack'])->name('hotel.billing.packs.buy');
        Route::put('hotel/billing/plan', [HotelPaymentController::class, 'changePlan'])->name('hotel.billing.plan');
    });

    Route::middleware('can:hotel.settings.manage')->group(function (): void {
        Route::get('hotel/setup', [SetupController::class, 'show'])->name('hotel.setup');
        Route::post('hotel/setup/steps/{step}/skip', [SetupController::class, 'skip'])->name('hotel.setup.skip');
        Route::post('hotel/setup/taxes', [SetupController::class, 'confirmTaxes'])->name('hotel.setup.taxes');
        Route::get('hotel/setup/templates/{kind}', [SetupController::class, 'template'])->name('hotel.setup.template');
        Route::post('hotel/setup/rooms', [SetupController::class, 'quickRooms'])->middleware('can:rooms.manage')->name('hotel.setup.rooms');
        Route::post('hotel/setup/import/{kind}', [SetupController::class, 'preview'])->middleware('throttle:20,1')->name('hotel.setup.import');
        Route::post('hotel/setup/import-confirm', [SetupController::class, 'confirm'])->middleware('throttle:20,1')->name('hotel.setup.import.confirm');
        Route::post('hotel/setup/pack-draft', [SetupController::class, 'draftPack'])->middleware(['can:assistant.manage', 'throttle:5,10'])->name('hotel.setup.pack-draft');
        Route::post('hotel/setup/golive', [SetupController::class, 'requestGoLive'])->middleware('throttle:10,1')->name('hotel.setup.golive');
    });

    Route::post('hotel/announcements/{announcement}/dismiss', [App\Domains\Platform\Http\Controllers\AnnouncementController::class, 'dismiss'])->name('hotel.announcements.dismiss');
    Route::middleware('can:staff.manage')->group(function (): void {
        Route::get('hotel/support', [App\Domains\Platform\Http\Controllers\SupportSessionController::class, 'index'])->name('hotel.support.index');
        Route::post('hotel/support/{session}/decide', [App\Domains\Platform\Http\Controllers\SupportSessionController::class, 'decide'])->name('hotel.support.decide');
        Route::post('hotel/support/{session}/stop', [App\Domains\Platform\Http\Controllers\SupportSessionController::class, 'stop'])->name('hotel.support.stop');
        Route::put('hotel/support/setting', [App\Domains\Platform\Http\Controllers\SupportSessionController::class, 'setting'])->name('hotel.support.setting');
    });

    Route::get('hotel/today', [TodayController::class, 'index'])->middleware('can:bookings.view')->name('hotel.today');
    Route::post('hotel/today/register-check', [TodayController::class, 'registerCheck'])->middleware('can:bookings.frontdesk')->name('hotel.today.register-check');
    Route::get('hotel/bookings/export', BookingExportController::class)->middleware(['can:bookings.export', 'throttle:10,1'])->name('hotel.bookings.export');
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
