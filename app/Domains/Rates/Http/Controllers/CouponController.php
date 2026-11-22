<?php

declare(strict_types=1);

namespace App\Domains\Rates\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Rates\Models\Coupon;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The hotel's discount codes (0.31): created and changed by those who set
 * prices; guests give a code on WhatsApp and the assistant applies it.
 */
final class CouponController extends Controller
{
    public function index(TenantContext $context, #[CurrentUser] User $user): Response
    {
        $hotel = $context->hotel();
        $savings = Booking::query()->whereNotNull('coupon_code')->selectRaw('coupon_code, count(*) as bookings, sum(coupon_discount_minor) as discount')
            ->groupBy('coupon_code')->get()->keyBy('coupon_code');

        return Inertia::render('hotel/coupons', [
            'currency' => $hotel->currency,
            'canManage' => $user->can('rates.manage'),
            'coupons' => Coupon::query()->orderByDesc('active')->orderBy('code')->get()->map(fn (Coupon $c): array => [
                'id' => $c->id,
                'code' => $c->code,
                'note' => $c->note,
                'kind' => $c->kind,
                'value' => $c->value,
                'valid_from' => $c->valid_from?->toDateString(),
                'valid_until' => $c->valid_until?->toDateString(),
                'max_uses' => $c->max_uses,
                'used_count' => $c->used_count,
                'min_nights' => $c->min_nights,
                'active' => $c->active,
                'bookings' => (int) ($savings[$c->code]->bookings ?? 0),
                'discount_minor' => (int) ($savings[$c->code]->discount ?? 0),
            ])->values(),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $coupon = Coupon::query()->create($this->validated($request, null));
        Audit::record('coupons.created', 'Discount code created', subject: $coupon, causer: $user, properties: ['code' => $coupon->code, 'kind' => $coupon->kind, 'value' => $coupon->value]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Code :code created.', ['code' => $coupon->code])]);

        return back();
    }

    public function update(Request $request, Coupon $coupon, #[CurrentUser] User $user): RedirectResponse
    {
        $coupon->update($this->validated($request, $coupon));
        Audit::record('coupons.updated', 'Discount code changed', subject: $coupon, causer: $user, properties: ['code' => $coupon->code, 'active' => $coupon->active]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Code :code saved.', ['code' => $coupon->code])]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Coupon $coupon): array
    {
        $request->merge(['code' => Coupon::normalise((string) $request->input('code'))]);
        $hotelId = app(TenantContext::class)->hotel()->getKey();

        $data = $request->validate([
            'code' => ['required', 'string', 'min:3', 'max:30', Rule::unique('coupons', 'code')->where('hotel_id', $hotelId)->ignore($coupon?->id)],
            'note' => ['nullable', 'string', 'max:120'],
            'kind' => ['required', Rule::in(Coupon::KINDS)],
            'value' => ['required', 'integer', 'min:1', $request->input('kind') === 'percent' ? 'max:90' : 'max:100000000'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'min_nights' => ['nullable', 'integer', 'min:1', 'max:60'],
            'active' => ['required', 'boolean'],
        ], [
            'code.unique' => __('This code already exists.'),
        ]);

        return $data;
    }
}
