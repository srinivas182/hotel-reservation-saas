<?php

declare(strict_types=1);

namespace App\Domains\Rates\Coupons;

use App\Domains\Rates\Models\Coupon;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Coupons of the current hotel: whether a code applies to a stay, the
 * discount it gives, and counting a use once the booking is made.
 */
final class Coupons
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * The coupon for a code typed by a guest or staff, checked for this
     * stay. Throws with a reason the guest can be told.
     */
    public function check(string $code, int $nights): Coupon
    {
        $normalised = Coupon::normalise($code);
        $coupon = $normalised === '' ? null : Coupon::query()->where('code', $normalised)->first();
        $today = HotelClock::today($this->context->hotel());

        return match (true) {
            $coupon === null || ! $coupon->active => throw new CouponNotValid(__('This code is not valid.')),
            $coupon->valid_from !== null && $today->lt($coupon->valid_from) => throw new CouponNotValid(__('This code is not valid yet.')),
            $coupon->valid_until !== null && $today->gt($coupon->valid_until) => throw new CouponNotValid(__('This code has expired.')),
            $coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses => throw new CouponNotValid(__('This code has already been used the maximum number of times.')),
            $coupon->min_nights !== null && $nights < $coupon->min_nights => throw new CouponNotValid(trans_choice('This code needs a stay of at least :count night.|This code needs a stay of at least :count nights.', $coupon->min_nights)),
            default => $coupon,
        };
    }

    /**
     * What the coupon takes off a total: a percentage (rounded down) or a
     * fixed amount, never more than the total.
     */
    public function discount(Coupon $coupon, Money $total): Money
    {
        $off = $coupon->kind === 'fixed'
            ? Money::ofMinor($coupon->value, $total->getCurrency())
            : $total->multipliedBy($coupon->value)->dividedBy(100, RoundingMode::Down);

        return $off->isGreaterThan($total) ? $total : $off;
    }

    /** Count one use (the booking is made). */
    public function redeem(string $code): void
    {
        Coupon::query()->where('code', Coupon::normalise($code))->update(['used_count' => DB::raw('used_count + 1')]);
    }
}
