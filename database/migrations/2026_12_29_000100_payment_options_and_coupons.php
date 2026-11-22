<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pay now or pay at the hotel, and coupons (0.31.0).
 *
 * coupons: the hotel's discount codes (percent or fixed amount), with
 * dates, a use limit and a minimum stay.
 * booking_requests / bookings: the coupon given and the discount it took
 * off; bookings also record the pay-now discount and whether the stay
 * was paid in advance.
 * hotel_assistant_settings: the pay-now discount (0: not offered) and the
 * time a pay-at-the-hotel room is kept on the arrival day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('note', 120)->nullable();
            $table->string('kind', 8)->default('percent');
            $table->unsignedBigInteger('value');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedSmallInteger('min_nights')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['hotel_id', 'code']);
        });

        Schema::table('booking_requests', function (Blueprint $table): void {
            $table->string('coupon_code', 30)->nullable();
            $table->unsignedBigInteger('discount_minor')->default(0);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('coupon_code', 30)->nullable();
            $table->unsignedBigInteger('coupon_discount_minor')->default(0);
            $table->unsignedBigInteger('prepay_discount_minor')->default(0);
            $table->timestamp('prepaid_at')->nullable();
        });

        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('prepay_discount_percent')->default(0);
            $table->string('pay_at_hotel_hold_until', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->dropColumn(['prepay_discount_percent', 'pay_at_hotel_hold_until']);
        });
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn(['coupon_code', 'coupon_discount_minor', 'prepay_discount_minor', 'prepaid_at']);
        });
        Schema::table('booking_requests', function (Blueprint $table): void {
            $table->dropColumn(['coupon_code', 'discount_minor']);
        });
        Schema::dropIfExists('coupons');
    }
};
