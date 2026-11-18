<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint E: simple mode by role, larger text, guided tours and shift
 * checklists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('ui_mode', 10)->nullable(); // simple, full; null: the role's default
            $table->boolean('large_text')->default(false);
            $table->json('tours_done')->nullable(); // tour keys this person finished or skipped
            $table->timestamps();

            $table->unique(['hotel_id', 'user_id']);
        });

        Schema::table('hotel_settings', function (Blueprint $table): void {
            $table->json('simple_mode_roles')->nullable(); // role ids; null: reception
        });

        Schema::create('shift_checklists', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('shift', 10); // opening, closing
            $table->json('items'); // list of task texts
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('shift_checklist_ticks', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_checklist_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('item_key', 64); // hash of the task text: renaming a task starts it fresh
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['shift_checklist_id', 'day', 'item_key'], 'shift_ticks_unique');
            $table->index(['hotel_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_checklist_ticks');
        Schema::dropIfExists('shift_checklists');
        Schema::table('hotel_settings', fn (Blueprint $table) => $table->dropColumn('simple_mode_roles'));
        Schema::dropIfExists('staff_preferences');
    }
};
