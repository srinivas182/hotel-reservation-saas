<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform catalogue (not per hotel).
        Schema::create('plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 40)->unique();
            $table->json('name');
            $table->json('description')->nullable();
            $table->json('modules');
            $table->unsignedSmallInteger('staff_limit')->nullable();
            $table->unsignedInteger('ai_credits');
            $table->unsignedInteger('email_credits');
            $table->json('prices'); // {"XOF": {"monthly": 25000, "yearly": 250000}, …} in minor units
            $table->unsignedSmallInteger('trial_days')->default(14);
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('credit_packs', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->json('name');
            $table->string('kind', 10); // ai, email
            $table->unsignedInteger('credits');
            $table->json('prices'); // {"XOF": 10000, …}
            $table->unsignedSmallInteger('valid_months')->default(12);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('billing_settings', function (Blueprint $table): void {
            $table->id();
            $table->json('ai_weights')->nullable(); // task => hundredths of a credit
            $table->unsignedTinyInteger('buffer_percent')->default(5);
            $table->timestamps();
        });

        // Per hotel.
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUlid('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20); // trialing, active, cancelled
            $table->string('interval', 10); // monthly, yearly
            $table->char('currency', 3);
            $table->unsignedBigInteger('price_minor');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('trial_ends_at')->nullable();
            $table->foreignUlid('pending_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->json('warnings')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->foreignUlid('from_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignUlid('to_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->bigInteger('amount_minor')->default(0); // to invoice (renewals, pro-rata upgrades)
            $table->char('currency', 3);
            $table->timestamp('invoiced_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('credit_buckets', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // ai, email
            $table->string('source', 20); // allowance, trial, pack, adjustment
            $table->bigInteger('granted'); // hundredths of a credit
            $table->bigInteger('used')->default(0);
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('credit_pack_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 300)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hotel_id', 'kind', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_buckets');
        Schema::dropIfExists('subscription_changes');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('billing_settings');
        Schema::dropIfExists('credit_packs');
        Schema::dropIfExists('plans');
    }
};
