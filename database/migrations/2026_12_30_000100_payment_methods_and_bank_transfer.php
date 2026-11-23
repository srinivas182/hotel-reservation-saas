<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        // The new index starts with hotel_id, so the foreign key keeps an index.
        // (Both checks make a re-run after a partial rollback safe.)
        if (! Schema::hasIndex('merchant_accounts', ['hotel_id', 'driver'], 'unique')) {
            Schema::table('merchant_accounts', function (Blueprint $table): void {
                $table->unique(['hotel_id', 'driver']);
            });
        }
        if (Schema::hasIndex('merchant_accounts', ['hotel_id'], 'unique')) {
            Schema::table('merchant_accounts', function (Blueprint $table): void {
                $table->dropUnique(['hotel_id']);
            });
        }

        Schema::table('deposit_attempts', function (Blueprint $table): void {
            // migration-safety: reviewed — only relaxes NOT NULL on merchant_account_id
            // (bank transfers have no provider account); no data is changed or lost,
            // and the unique index on merchant_accounts is replaced, never dropped first.
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

        // A hotel that already uses two providers keeps both accounts: the
        // one-account-per-hotel index comes back only when it holds, so a
        // rollback never deletes a hotel's keys (the older code reads the first).
        $shared = DB::table('merchant_accounts')->select('hotel_id')->groupBy('hotel_id')->havingRaw('count(*) > 1')->exists();
        if (! $shared) {
            Schema::table('merchant_accounts', function (Blueprint $table): void {
                $table->unique(['hotel_id']);
            });
            Schema::table('merchant_accounts', function (Blueprint $table): void {
                $table->dropUnique(['hotel_id', 'driver']);
            });
        }
    }
};
