<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Http\Controllers;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Bookings\BookingChannel;
use App\Domains\Bookings\BookingService;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Exceptions\BookingNotPossible;
use App\Domains\Bookings\GuestDirectory;
use App\Domains\Bookings\Http\Requests\StoreBookingRequest;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Models\BookingRoom;
use App\Domains\Bookings\NewBooking;
use App\Domains\Bookings\RoomAssignments;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Hotel\Support\Locales;
use App\Domains\Rates\Quote\QuotePresenter;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class BookingController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): Response
    {
        $hotel = $this->context->hotel();
        $locale = $hotel->default_locale;

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string'],
            'channel' => ['nullable', 'string'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));

        $bookings = Booking::query()
            ->with(['guest', 'category'])
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('reference', 'like', "%{$search}%")
                ->orWhereHas('guest', fn ($g) => $g
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->when(preg_match('/\d{3,}/', $search) === 1, fn ($g) => $g->orWhere('phone_e164', 'like', '%'.preg_replace('/[^0-9]/', '', $search).'%')))))
            ->when(BookingStatus::tryFrom((string) ($filters['status'] ?? '')) !== null, fn ($query) => $query->where('status', $filters['status']))
            ->when(BookingChannel::tryFrom((string) ($filters['channel'] ?? '')) !== null, fn ($query) => $query->where('channel', $filters['channel']))
            ->when(isset($filters['from']), fn ($query) => $query->where('check_out', '>', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->where('check_in', '<=', $filters['to']))
            ->orderBy('check_in')
            ->orderBy('reference')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Booking $booking): array => $this->summary($booking, $locale));

        return Inertia::render('hotel/bookings/index', [
            'bookings' => $bookings,
            'filters' => $filters,
            'statuses' => collect(BookingStatus::cases())->map(fn (BookingStatus $s): array => ['value' => $s->value, 'label' => $s->label()])->values(),
            'channels' => collect(BookingChannel::cases())->map(fn (BookingChannel $c): array => ['value' => $c->value, 'label' => $c->label()])->values(),
            'canCreate' => $request->user()?->can('bookings.create') === true,
            'canExport' => $request->user()?->can('bookings.export') === true,
        ]);
    }

    public function create(Request $request, QuotePresenter $quotes, GuestDirectory $guests): Response
    {
        $hotel = $this->context->hotel();
        $locale = $hotel->default_locale;

        return Inertia::render('hotel/bookings/create', [
            'currency' => $hotel->currency,
            'today' => CarbonImmutable::now($hotel->timezone)->toDateString(),
            'categories' => RoomCategory::query()->where('active', true)->orderBy('sort_order')->orderBy('code')->get()
                ->map(fn (RoomCategory $category): array => [
                    'id' => $category->id,
                    'name' => $category->localizedName($locale, $locale),
                    'extra_bed_allowed' => $category->extra_bed_allowed,
                ])->values(),
            'channels' => collect(BookingChannel::cases())
                ->reject(fn (BookingChannel $channel): bool => $channel === BookingChannel::WhatsApp)
                ->map(fn (BookingChannel $c): array => ['value' => $c->value, 'label' => $c->label()])->values(),
            'locales' => Locales::SUPPORTED,
            'canOverridePrice' => $request->user()?->can('bookings.override_price') === true,
            'quote' => Inertia::optional(fn (): array => $quotes->fromRequest($request)),
            'guest' => Inertia::optional(function () use ($request, $guests): ?array {
                $guest = $guests->findByPhone((string) $request->query('phone', ''));

                return $guest === null ? null : [
                    'first_name' => $guest->first_name,
                    'last_name' => $guest->last_name,
                    'email' => $guest->email,
                    'preferred_locale' => $guest->preferred_locale,
                    'bookings' => $guest->bookings()->count(),
                ];
            }),
        ]);
    }

    public function store(StoreBookingRequest $request, BookingService $bookings, #[CurrentUser] User $user): RedirectResponse
    {
        $hotel = $this->context->hotel();
        $data = $request->validated();

        if (isset($data['override_total']) && ! $user->can('bookings.override_price')) {
            abort(403);
        }

        try {
            $booking = $bookings->create(new NewBooking(
                stay: new StayRequest(
                    categoryId: (string) $data['room_category_id'],
                    checkIn: CarbonImmutable::parse((string) $data['check_in']),
                    checkOut: CarbonImmutable::parse((string) $data['check_out']),
                    adults: (int) $data['adults'],
                    childAges: array_values(array_map('intval', (array) ($data['children_ages'] ?? []))),
                    rooms: (int) $data['rooms'],
                    breakfast: (bool) $data['breakfast'],
                    extraBed: (bool) $data['extra_bed'],
                ),
                guest: [
                    'first_name' => (string) $data['first_name'],
                    'last_name' => $data['last_name'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'email' => $data['email'] ?? null,
                    'preferred_locale' => $data['preferred_locale'] ?? null,
                ],
                channel: BookingChannel::from((string) $data['channel']),
                status: BookingStatus::from((string) $data['status']),
                externalReference: $data['external_reference'] ?? null,
                groupLabel: $data['group_label'] ?? null,
                notes: $data['notes'] ?? null,
                paymentNote: $data['payment_note'] ?? null,
                overrideTotal: isset($data['override_total'])
                    ? Money::of((string) $data['override_total'], $hotel->currency, roundingMode: RoundingMode::HalfUp)
                    : null,
                overrideReason: $data['override_reason'] ?? null,
            ), $user);
        } catch (BookingNotPossible $exception) {
            throw ValidationException::withMessages(['room_category_id' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Booking :reference saved.', ['reference' => $booking->reference])]);

        return to_route('hotel.bookings.show', $booking);
    }

    public function show(Request $request, Booking $booking, RoomAssignments $assignments, QuotePresenter $quotes): Response
    {
        $hotel = $this->context->hotel();
        $locale = $hotel->default_locale;
        $booking->load(['guest', 'category', 'bookingRooms.room']);
        $money = fn (int $minor): string => MoneyFormatter::format(Money::ofMinor($minor, $booking->currency), $locale);
        $snapshot = $booking->price_snapshot;

        $timeline = AuditLog::query()
            ->where('hotel_id', $hotel->getKey())
            ->where('subject_type', $booking->getMorphClass())
            ->where('subject_id', $booking->id)
            ->with('causer')
            ->latest('id')
            ->get()
            ->map(fn (AuditLog $entry): array => [
                'event' => $entry->event,
                'description' => __($entry->description),
                'by' => $entry->causer instanceof User ? $entry->causer->name : null,
                'at' => $entry->created_at?->setTimezone($hotel->timezone)->format('Y-m-d H:i'),
            ])->values();

        return Inertia::render('hotel/bookings/show', [
            'booking' => [
                ...$this->summary($booking, $locale),
                'external_reference' => $booking->external_reference,
                'group_label' => $booking->group_label,
                'adults' => $booking->adults,
                'children_ages' => $booking->children_ages,
                'breakfast' => $booking->breakfast,
                'extra_bed' => $booking->extra_bed,
                'notes' => $booking->notes,
                'payment_note' => $booking->payment_note,
                'price_overridden' => $booking->price_overridden,
                'override_reason' => $booking->override_reason,
                'tentative_until' => $booking->tentative_until?->setTimezone($hotel->timezone)->format('Y-m-d H:i'),
                'guest' => [
                    'name' => $booking->guest?->name(),
                    'phone' => $booking->guest?->phone_e164,
                    'email' => $booking->guest?->email,
                    'preferred_locale' => $booking->guest?->preferred_locale,
                ],
                'rooms_list' => $booking->bookingRooms->map(fn (BookingRoom $room): array => [
                    'position' => $room->position,
                    'room_id' => $room->room_id,
                    'number' => $room->room?->number,
                ])->values(),
                'price' => [
                    'nights' => array_map(fn (array $night): array => [
                        'date' => $night['date'],
                        'rate' => $money((int) $night['rate_minor']),
                        'label' => $night['label'],
                    ], (array) ($snapshot['nights'] ?? [])),
                    'lines' => array_map(fn (array $line): array => [
                        'label' => $line['label'],
                        'quantity' => $line['quantity'],
                        'amount' => $money((int) $line['amount_minor']),
                    ], (array) ($snapshot['lines'] ?? [])),
                    'taxes' => array_map(fn (array $tax): array => [
                        'name' => is_array($tax['name']) ? ($tax['name'][$locale] ?? (string) reset($tax['name'])) : (string) $tax['name'],
                        'amount' => $money((int) $tax['amount_minor']),
                        'inclusive' => (bool) $tax['inclusive'],
                    ], (array) ($snapshot['taxes'] ?? [])),
                    'quoted_total' => $money((int) ($snapshot['total_minor'] ?? $booking->total_minor)),
                ],
            ],
            'timeline' => $timeline,
            'actions' => $this->actions($request, $booking),
            'eligibleRooms' => in_array($booking->status, [BookingStatus::Tentative, BookingStatus::Confirmed, BookingStatus::CheckedIn], true)
                ? $assignments->eligibleRooms($booking)->map(fn (Room $room): array => [
                    'id' => $room->id,
                    'number' => $room->number,
                    'floor' => $room->floor,
                ])->values()
                : [],
            'suggestedRoom' => $booking->status === BookingStatus::Confirmed ? $assignments->suggest($booking)?->id : null,
            'editStay' => [
                'room_category_id' => $booking->room_category_id,
                'check_in' => $booking->check_in->toDateString(),
                'check_out' => $booking->check_out->toDateString(),
                'adults' => $booking->adults,
                'children' => implode(', ', array_map(fn ($age): string => (string) $age, array_filter($booking->children_ages, fn ($age): bool => $age !== null))),
                'rooms' => $booking->rooms,
                'breakfast' => $booking->breakfast,
                'extra_bed' => $booking->extra_bed,
            ],
            'categories' => RoomCategory::query()->where('active', true)->orderBy('sort_order')->orderBy('code')->get()
                ->map(fn (RoomCategory $category): array => [
                    'id' => $category->id,
                    'name' => $category->localizedName($locale, $locale),
                    'extra_bed_allowed' => $category->extra_bed_allowed,
                ])->values(),
            'today' => CarbonImmutable::now($hotel->timezone)->toDateString(),
            'quote' => Inertia::optional(fn (): array => $quotes->fromRequest($request)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Booking $booking, string $locale): array
    {
        return [
            'id' => $booking->id,
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'status_label' => $booking->status->label(),
            'channel' => $booking->channel->value,
            'channel_label' => $booking->channel->label(),
            'guest_name' => $booking->guest?->name(),
            'guest_phone' => $booking->guest?->phone_e164,
            'category' => $booking->category?->localizedName($locale, $locale),
            'check_in' => $booking->check_in->toDateString(),
            'check_out' => $booking->check_out->toDateString(),
            'nights' => $booking->nights(),
            'rooms' => $booking->rooms,
            'guests' => $booking->adults + count($booking->children_ages),
            'total' => MoneyFormatter::format(Money::ofMinor($booking->total_minor, $booking->currency), $locale),
        ];
    }

    /**
     * Which actions the user can take on the booking now.
     *
     * @return array<string, bool>
     */
    private function actions(Request $request, Booking $booking): array
    {
        $user = $request->user();
        $manage = $user?->can('bookings.manage') === true;
        $frontdesk = $user?->can('bookings.frontdesk') === true;
        $status = $booking->status;
        $today = HotelClock::today($this->context->hotel());
        $current = in_array($status, [BookingStatus::Tentative, BookingStatus::Confirmed], true);

        return [
            'confirm' => $manage && $status === BookingStatus::Tentative,
            'modify' => $manage && $current,
            'cancel' => $manage && $current,
            'no_show' => $manage && $status === BookingStatus::Confirmed && $booking->check_in->lte($today),
            'assign_rooms' => $frontdesk && in_array($status, [BookingStatus::Tentative, BookingStatus::Confirmed, BookingStatus::CheckedIn], true),
            'check_in' => $frontdesk && $status === BookingStatus::Confirmed && $booking->check_in->lte($today) && $booking->check_out->gt($today),
            'check_out' => $frontdesk && $status === BookingStatus::CheckedIn,
            'early_departure' => $status === BookingStatus::CheckedIn && $booking->check_out->gt($today->max($booking->check_in->addDay())),
            'override_price' => $user?->can('bookings.override_price') === true,
        ];
    }
}
