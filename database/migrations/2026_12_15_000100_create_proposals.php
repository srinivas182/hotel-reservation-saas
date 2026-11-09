<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('plan_id')->constrained()->restrictOnDelete();
            $table->string('currency', 3);
            $table->string('cycle', 10); // monthly, yearly
            $table->unsignedTinyInteger('discount_percent')->default(0);
            $table->json('roi'); // inputs used for the return-on-investment section
            $table->string('locale', 5)->default('fr');
            $table->text('note')->nullable(); // encrypted: a personal word from sales
            $table->string('token', 64)->unique();
            $table->date('valid_until');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('signup_code_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
