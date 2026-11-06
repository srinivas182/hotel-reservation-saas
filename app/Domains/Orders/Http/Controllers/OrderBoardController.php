<?php

declare(strict_types=1);

namespace App\Domains\Orders\Http\Controllers;

use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Orders\Exceptions\OrderNotPossible;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderLine;
use App\Domains\Orders\NewOrder;
use App\Domains\Orders\OrderDelivery;
use App\Domains\Orders\OrderPayment;
use App\Domains\Orders\OrderService;
use App\Domains\Orders\OrderSource;
use App\Domains\Orders\OrderStatus;
use App\Domains\Orders\Stays;
use App\Domains\Restaurant\Menu\MenuCatalog;
use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Brick\Money\Money;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The kitchen and bar board: new, accepted and ready orders, and taking
 * orders by phone or at the counter.
 */
final class OrderBoardController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly OrderService $orders,
    ) {}

    public function index(MenuCatalog $catalog): Response
    {
        $hotel = $this->context->hotel();
        $locale = $hotel->default_locale;
        $outlets = Outlet::query()->where('active', true)->where('orders_enabled', true)->orderBy('sort_order')->get();

        $open = Order::query()->with(['lines', 'guest', 'outlet'])
            ->whereIn('status', array_map(fn (OrderStatus $s): string => $s->value, OrderStatus::open()))
            ->orderBy('created_at')->get();
        $closedToday = Order::query()->with(['lines', 'guest', 'outlet'])
            ->whereNotNull('closed_at')->where('closed_at', '>=', now($hotel->timezone)->startOfDay()->utc())
            ->orderByDesc('closed_at')->limit(30)->get();

        $stays = Booking::query()->with(['bookingRooms.room', 'guest'])->where('status', BookingStatus::CheckedIn->value)->get()
            ->map(fn (Booking $b): array => ['id' => $b->id, 'guest' => $b->guest?->name(), 'rooms' => Stays::roomNumbers($b)])->values();

        return Inertia::render('hotel/restaurant-orders', [
            'columns' => collect([OrderStatus::New, OrderStatus::Accepted, OrderStatus::Ready])->map(fn (OrderStatus $s): array => [
                'status' => $s->value,
                'label' => $s->label(),
                'orders' => $open->where('status', $s)->map(fn (Order $o): array => $this->present($o))->values(),
            ])->values(),
            'closed' => $closedToday->map(fn (Order $o): array => $this->present($o))->values(),
            'outlets' => $outlets->map(fn (Outlet $o): array => [
                'id' => $o->id,
                'name' => $o->localizedName($locale, $locale),
                'dishes' => collect($catalog->forGuests($o, $locale)['sections'])->flatMap(fn (array $s) => $s['items'])->map(fn (array $i): array => ['id' => $i['id'], 'name' => $i['name'], 'price' => $i['price_text']])->values(),
            ])->values(),
            'stays' => $stays,
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'outlet_id' => ['required', 'string'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:50'],
            'delivery' => ['required', Rule::enum(OrderDelivery::class)],
            'booking_id' => ['nullable', 'string'],
            'room_number' => ['nullable', 'string', 'max:20'],
            'location_note' => ['nullable', 'string', 'max:200'],
            'payment' => ['required', Rule::enum(OrderPayment::class)],
            'source' => ['required', Rule::in([OrderSource::Phone->value, OrderSource::Counter->value])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $order = $this->orders->place(new NewOrder(
                outletId: (string) $data['outlet_id'],
                items: array_values(array_map(fn (array $i): array => ['menu_item_id' => (int) $i['menu_item_id'], 'quantity' => (int) $i['quantity']], $data['items'])),
                source: OrderSource::from((string) $data['source']),
                delivery: OrderDelivery::from((string) $data['delivery']),
                payment: OrderPayment::from((string) $data['payment']),
                bookingId: $data['booking_id'] ?? null,
                roomNumber: $data['room_number'] ?? null,
                locationNote: $data['location_note'] ?? null,
                notes: $data['notes'] ?? null,
            ), $user);
        } catch (OrderNotPossible $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Order :reference added.', ['reference' => $order->reference])]);

        return back();
    }

    public function status(Request $request, Order $order, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'eta_minutes' => ['nullable', 'integer', Rule::in([5, 10, 15, 20, 30, 45, 60])],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);
        $to = OrderStatus::from((string) $data['status']);

        if ($to === OrderStatus::Accepted && ! isset($data['eta_minutes'])) {
            throw ValidationException::withMessages(['eta_minutes' => __('Say how long it will take.')]);
        }

        try {
            $this->orders->move($order, $to, $user, isset($data['eta_minutes']) ? (int) $data['eta_minutes'] : null, $data['reason'] ?? null);
        } catch (OrderNotPossible $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Order $o): array
    {
        $hotel = $this->context->hotel();

        return [
            'id' => $o->id,
            'reference' => $o->reference,
            'status' => $o->status->value,
            'outlet' => $o->outlet?->localizedName($hotel->default_locale, $hotel->default_locale),
            'guest' => $o->guest?->name(),
            'where' => $o->delivery === OrderDelivery::Room ? __('Room :number', ['number' => $o->room_number]) : ($o->location_note ?: $o->delivery->label()),
            'payment' => $o->payment->label(),
            'source' => $o->source->label(),
            'total' => MoneyFormatter::format(Money::ofMinor($o->total_minor, $o->currency), $hotel->default_locale),
            'lines' => $o->lines->map(fn (OrderLine $l): array => ['quantity' => $l->quantity, 'name' => $l->name])->values(),
            'notes' => $o->notes,
            'eta_minutes' => $o->eta_minutes,
            'placed_at' => $o->created_at?->setTimezone($hotel->timezone)->format('H:i'),
            'waiting_minutes' => $o->created_at === null ? 0 : (int) $o->created_at->diffInMinutes(now()),
            'next' => array_map(fn (OrderStatus $s): array => ['value' => $s->value, 'label' => $s->label()], $o->status->allowedNext()),
        ];
    }
}
