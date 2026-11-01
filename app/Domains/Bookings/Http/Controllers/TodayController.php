<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Availability\AvailabilityService;
use App\Domains\Bookings\FrontDesk;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Models\BookingRoom;
use App\Domains\Bookings\Models\RegisterCheck;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Today screen: reception's home.
 */
final class TodayController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FrontDesk $desk,
    ) {}

    public function index(Request $request, AvailabilityService $availability): Response
    {
        $hotel = $this->context->hotel();
        $locale = $hotel->default_locale;
        $today = $this->desk->today();

        $categories = RoomCategory::query()->where('active', true)->orderBy('sort_order')->orderBy('code')->get();
        $ids = array_values(array_map('strval', $categories->pluck('id')->all()));
        $tonight = $ids === [] ? [] : $availability->forRange($ids, $today, $today);

        $present = fn (Booking $booking, bool $suggest = false): array => [
            'id' => $booking->id,
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'status_label' => $booking->status->label(),
            'guest' => $booking->guest?->name(),
            'phone' => $booking->guest?->phone_e164,
            'category' => $booking->category?->localizedName($locale, $locale),
            'check_in' => $booking->check_in->toDateString(),
            'check_out' => $booking->check_out->toDateString(),
            'rooms' => $booking->rooms,
            'room_numbers' => $booking->bookingRooms->map(fn (BookingRoom $line): ?string => $line->room?->number)->filter()->values()->all(),
            'tentative_until' => $booking->tentative_until?->setTimezone($hotel->timezone)->format('Y-m-d H:i'),
            'overdue' => $booking->check_out->lt($today),
            'suggested_rooms' => $suggest ? (object) $this->desk->suggestedRooms($booking) : null,
        ];

        $check = RegisterCheck::query()->with('checker')->where('night', $today->toDateString())->latest('id')->first();

        /** @var \Carbon\CarbonImmutable $localized */
        $localized = $today->locale($locale);

        return Inertia::render('hotel/today', [
            'date' => $today->toDateString(),
            'dateLabel' => $localized->isoFormat('dddd D MMMM YYYY'),
            'arrivals' => $this->desk->arrivals()->map(fn (Booking $b): array => $present($b, true))->values(),
            'inHouse' => $this->desk->inHouse()->map(fn (Booking $b): array => $present($b))->values(),
            'departures' => $this->desk->departures()->map(fn (Booking $b): array => $present($b))->values(),
            'noShows' => $this->desk->possibleNoShows()->map(fn (Booking $b): array => $present($b))->values(),
            'tentative' => $this->desk->tentativeDueSoon()->map(fn (Booking $b): array => $present($b))->values(),
            'freeTonight' => $categories->map(fn (RoomCategory $category): array => [
                'name' => $category->localizedName($locale, $locale),
                'available' => $tonight[$category->id][$today->toDateString()]['available'] ?? 0,
                'sellable' => $tonight[$category->id][$today->toDateString()]['sellable'] ?? 0,
            ])->values(),
            'board' => $this->desk->roomsBoard(),
            'registerCheck' => $check === null ? null : [
                'matches' => $check->matches,
                'notes' => $check->notes,
                'by' => $check->checker?->name,
                'at' => $check->created_at?->setTimezone($hotel->timezone)->format('H:i'),
            ],
            'can' => [
                'frontdesk' => $request->user()?->can('bookings.frontdesk') === true,
                'manage' => $request->user()?->can('bookings.manage') === true,
                'create' => $request->user()?->can('bookings.create') === true,
            ],
        ]);
    }

    public function registerCheck(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'matches' => ['required', 'boolean'],
            'notes' => [$request->boolean('matches') ? 'nullable' : 'required', 'nullable', 'string', 'max:1000'],
        ], ['notes.required' => __('Say what does not match.')]);

        $rooms = $this->desk->occupiedTonight();

        $check = RegisterCheck::query()->create([
            'night' => $this->desk->today(),
            'checked_by' => $user->id,
            'matches' => (bool) $data['matches'],
            'rooms' => $rooms,
            'notes' => $data['notes'] ?? null,
        ]);

        Audit::record('bookings.register_checked', $check->matches ? 'Register checked: matches' : 'Register checked: differences found', subject: $this->context->hotel(), causer: $user, properties: [
            'night' => $check->night->toDateString(),
            'matches' => $check->matches,
            'rooms' => $rooms,
            'notes' => $check->notes,
        ]);

        Inertia::flash('toast', ['type' => $check->matches ? 'success' : 'warning', 'message' => $check->matches ? __('Register check saved.') : __('Differences recorded. Please correct the bookings.')]);

        return back();
    }
}
