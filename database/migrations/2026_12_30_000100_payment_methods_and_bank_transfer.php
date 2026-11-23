<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Several payment methods per hotel, and bank transfer (0.36.0).
 *
 * merchant_accounts: one account per provider and hotel (CinetPay and
 * Stripe can both be on), instead of one account per hotel.
 * deposit_attempts: a bank transfer is an attempt without a provider
 * account: the guest transfers with its reference before a deadline,
 * sends the receipt, and staff approve or reject it.
 * hotel_assistant_settings: the hotel's bank details, encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_accounts', function (Blueprint $table): void {
            // The new index starts with hotel_id, so the foreign key keeps an index.
            $table->unique(['hotel_id', 'driver']);
        });
        Schema::table('merchant_accounts', function (Blueprint $table): void {
            $table->dropUnique(['hotel_id']);
        });

        Schema::table('deposit_attempts', function (Blueprint $table): void {
            $table->foreignId('merchant_account_id')->nullable()->change();
            $table->string('method', 20)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('proof_path', 200)->nullable();
            $table->timestamp('proof_at')->nullable();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            $table->index(['hotel_id', 'method', 'status']);
        });

        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->text('bank_transfer')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->dropColumn('bank_transfer');
        });

        Schema::table('deposit_attempts', function (Blueprint $table): void {
            $table->dropIndex(['hotel_id', 'method', 'status']);
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['method', 'expires_at', 'proof_path', 'proof_at', 'decided_at']);
        });

        Schema::table('merchant_accounts', function (Blueprint $table): void {
            $table->unique(['hotel_id']);
        });
        Schema::table('merchant_accounts', function (Blueprint $table): void {
            $table->dropUnique(['hotel_id', 'driver']);
        });
    }
};
