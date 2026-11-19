<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint F: deposits paid by mobile money through a link.
 *
 * merchant_accounts: the hotel's own payment account (money goes straight
 * to the hotel; the platform never holds guest money).
 * deposit_links: one per tentative booking, with what was paid, how, the
 * receipt and any refund owed.
 * deposit_attempts: each time the guest starts paying (deposit or full).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('driver', 20);
            $table->string('mode', 10)->default('live');
            $table->text('credentials');
            $table->boolean('active')->default(false);
            $table->timestamp('checked_at')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->timestamps();
        });

        Schema::create('deposit_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            // The link's token, encrypted, so reminders can send the same link again.
            $table->text('token');
            $table->string('status', 12)->default('open');
            $table->unsignedBigInteger('deposit_minor');
            $table->string('currency', 3);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('paid_minor')->nullable();
            $table->string('method', 20)->nullable();
            $table->string('paid_via', 10)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignUlid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('receipt_number', 30)->nullable();
            $table->unsignedBigInteger('folio_line_id')->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->string('proof_message_id', 26)->nullable();
            $table->string('refund_status', 10)->nullable();
            $table->unsignedBigInteger('refund_due_minor')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignUlid('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('refund_note', 200)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hotel_id', 'status']);
            $table->index(['hotel_id', 'paid_at']);
            $table->index(['booking_id', 'status']);
        });

        Schema::create('deposit_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('deposit_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_account_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40)->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 10)->default('pending');
            $table->string('provider_token', 200)->nullable();
            $table->text('checkout_url')->nullable();
            $table->string('failure', 300)->nullable();
            $table->timestamps();
        });

        // Those who get new booking requests also get the deposit alerts.
        foreach (DB::table('staff_alert_settings')->whereNotNull('alerts')->get(['id', 'alerts']) as $row) {
            $alerts = json_decode((string) $row->alerts, true);
            if (is_array($alerts) && in_array('new_request', $alerts, true)) {
                DB::table('staff_alert_settings')->where('id', $row->id)->update([
                    'alerts' => json_encode(array_values(array_unique([...$alerts, 'deposit_paid', 'deposit_proof', 'deposit_refund']))),
                ]);
            }
        }

        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->boolean('deposit_online')->default(true);
            $table->boolean('deposit_allow_full')->default(true);
            // Refundable when cancelled at least this many days before arrival; null: never.
            $table->unsignedSmallInteger('deposit_refund_days')->nullable()->default(2);
        });
    }

    public function down(): void
    {
        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->dropColumn(['deposit_online', 'deposit_allow_full', 'deposit_refund_days']);
        });
        Schema::dropIfExists('deposit_attempts');
        Schema::dropIfExists('deposit_links');
        Schema::dropIfExists('merchant_accounts');
    }
};
