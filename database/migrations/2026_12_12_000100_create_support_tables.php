<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_settings', function (Blueprint $table): void {
            $table->id();
            $table->json('response_hours'); // {"default": 24, "<plan code>": 8}
            $table->boolean('ai_drafts')->default(true);
            $table->foreignUlid('whatsapp_hotel_id')->nullable()->constrained('hotels')->nullOnDelete(); // account whose WhatsApp number sends support replies
            $table->string('whatsapp_template', 80)->default('support_reply');
            $table->timestamps();
        });

        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 150);
            $table->string('category', 20);
            $table->string('status', 20)->default('open'); // open, waiting_hotel, solved
            $table->string('priority', 10)->default('normal'); // normal, high, urgent
            $table->foreignUlid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('context')->nullable(); // page, screen, version, role, browser, error reference
            $table->boolean('notify_whatsapp')->default(false);
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('solved_at')->nullable();
            $table->string('rating', 4)->nullable(); // good, bad
            $table->string('rating_comment', 500)->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'first_response_due_at']);
        });

        Schema::create('support_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('side', 10); // hotel, support
            $table->boolean('internal')->default(false);
            $table->text('body'); // encrypted
            $table->json('attachments')->nullable();
            $table->string('via', 10)->default('app'); // app, whatsapp
            $table->boolean('ai_drafted')->default(false);
            $table->timestamps();
        });

        Schema::create('saved_replies', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 100);
            $table->json('body'); // per language, with {hotel}, {name}, {agent}
            $table->timestamps();
        });

        $now = now();
        DB::table('support_settings')->insert(['response_hours' => json_encode(['default' => 24, 'pro' => 8, 'complet' => 8]), 'ai_drafts' => true, 'whatsapp_template' => 'support_reply', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('saved_replies')->insert([
            ['title' => 'Template approval', 'body' => json_encode(['fr' => "Bonjour {name},\n\nLes modèles de messages WhatsApp doivent être approuvés par Meta, ce qui prend de quelques minutes à 24 h. Vous verrez leur statut dans WhatsApp → Modèles. Nous surveillons de notre côté.\n\nBien cordialement,\n{agent}", 'en' => "Hello {name},\n\nWhatsApp message templates must be approved by Meta, which takes from a few minutes to 24 hours. You can see their status in WhatsApp → Templates. We are keeping an eye on it too.\n\nKind regards,\n{agent}"]), 'created_at' => $now, 'updated_at' => $now],
            ['title' => 'Credits used up', 'body' => json_encode(['fr' => "Bonjour {name},\n\nLes crédits IA de {hotel} sont épuisés : l'assistant transmet donc les clients à votre équipe. Un pack de crédits se prend dans Abonnement et l'assistant reprend aussitôt.\n\nBien cordialement,\n{agent}", 'en' => "Hello {name},\n\n{hotel}'s AI credits are used up, so the assistant is passing guests to your team. You can buy a credit pack in Billing and the assistant resumes at once.\n\nKind regards,\n{agent}"]), 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_replies');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('support_settings');
    }
};
