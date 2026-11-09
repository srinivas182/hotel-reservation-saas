<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false)->index();
            $table->timestamp('demo_expires_at')->nullable(); // private demos (48 h); null for the permanent demo hotel
        });

        Schema::table('leads', function (Blueprint $table): void {
            $table->foreignUlid('demo_hotel_id')->nullable()->constrained('hotels')->nullOnDelete();
            $table->timestamp('demo_sent_at')->nullable();
            $table->timestamp('demo_opened_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('demo_hotel_id');
            $table->dropColumn(['demo_sent_at', 'demo_opened_at']);
        });
        Schema::table('hotels', fn (Blueprint $table) => $table->dropColumn(['is_demo', 'demo_expires_at']));
    }
};
