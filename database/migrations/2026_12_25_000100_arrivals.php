<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint G: the arrival card the day before.
 *
 * arrival_checks: per booking, what the guest answered to the card (coming,
 * not coming, a question, the arrival time, extras) and the evening flag
 * when they did not answer.
 * hotel_assistant_settings: the hotel's local directions, the transport
 * tip, when reception closes and what late guests should do, the extras
 * offered and a photo of the entrance.
 * deposit_links.purpose: Sprint F links now also carry the balance of a
 * confirmed stay, paid before arrival.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arrival_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('card_sent_at')->nullable();
            $table->string('card_how', 10)->nullable();
            $table->timestamp('pin_sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('question_at')->nullable();
            $table->string('eta_slot', 12)->nullable();
            $table->timestamp('eta_at')->nullable();
            $table->json('extras')->nullable();
            $table->timestamp('flagged_at')->nullable();
            $table->timestamps();

            $table->index(['hotel_id', 'card_sent_at']);
        });

        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->json('arrival_directions')->nullable();
            $table->json('arrival_tip')->nullable();
            $table->string('reception_closes_at', 5)->nullable();
            $table->json('night_arrival')->nullable();
            $table->json('arrival_extras')->nullable();
            $table->string('arrival_photo_path')->nullable();
        });

        Schema::table('deposit_links', function (Blueprint $table): void {
            $table->string('purpose', 10)->default('deposit');
        });

        // Those who get new booking requests also get the arrival alerts.
        foreach (DB::table('staff_alert_settings')->whereNotNull('alerts')->get(['id', 'alerts']) as $row) {
            $alerts = json_decode((string) $row->alerts, true);
            if (is_array($alerts) && in_array('new_request', $alerts, true)) {
                DB::table('staff_alert_settings')->where('id', $row->id)->update([
                    'alerts' => json_encode(array_values(array_unique([...$alerts, 'arrival_cancelling', 'arrival_request', 'arrival_unconfirmed']))),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('deposit_links', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->dropColumn(['arrival_directions', 'arrival_tip', 'reception_closes_at', 'night_arrival', 'arrival_extras', 'arrival_photo_path']);
        });
        Schema::dropIfExists('arrival_checks');
    }
};
