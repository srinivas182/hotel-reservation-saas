<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The companies that issue invoices (set by the platform).
        Schema::create('billing_entities', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('legal_name', 200);
            $table->char('country_code', 2);
            $table->text('address');
            $table->string('registration', 100)->nullable(); // e.g. RCCM
            $table->string('tax_id', 100)->nullable(); // e.g. NCC
            $table->string('email', 191);
            $table->string('phone', 30)->nullable();
            $table->string('invoice_prefix', 10);
            $table->json('tax_rules')->nullable(); // [{name, rate_bps}]
            $table->boolean('prices_include_tax')->default(false);
            $table->unsignedSmallInteger('payment_terms_days')->default(7);
            $table->text('payment_instructions')->nullable();
            $table->text('footer')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('invoice_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('billing_entity_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['billing_entity_id', 'year']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->restrictOnDelete();
            $table->foreignId('billing_entity_id')->constrained()->restrictOnDelete();
            $table->string('number', 40);
            $table->string('kind', 20); // invoice, credit_note
            $table->foreignUlid('credits_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('total_minor');
            $table->bigInteger('paid_minor')->default(0);
            $table->json('taxes'); // [{name, rate_bps, amount_minor}]
            $table->json('issuer'); // snapshot of the billing entity
            $table->json('customer'); // snapshot of the hotel's billing details
            $table->date('issued_on');
            $table->date('due_on');
            $table->timestamp('paid_at')->nullable();
            $table->json('reminders')->nullable();
            $table->string('reason', 300)->nullable(); // credit notes
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['billing_entity_id', 'number']);
            $table->index(['hotel_id', 'issued_on']);
        });

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('unit_minor');
            $table->bigInteger('amount_minor');
            $table->foreignId('subscription_change_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('invoice_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('method', 30);
            $table->string('reference', 100)->nullable();
            $table->date('received_on');
            $table->string('provider_reference', 191)->nullable()->unique(); // online payments (S13.3)
            $table->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->foreignId('billing_entity_id')->nullable()->constrained()->nullOnDelete();
            $table->json('billing_details')->nullable(); // name, address, tax_id, email
            $table->timestamp('reduced_at')->nullable();
            $table->date('grace_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('billing_entity_id');
            $table->dropColumn(['billing_details', 'reduced_at', 'grace_until']);
        });
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('billing_entities');
    }
};
