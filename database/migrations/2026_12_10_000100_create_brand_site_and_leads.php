<?php

declare(strict_types=1);

use App\Domains\Site\SiteDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60);
            $table->json('tagline')->nullable();
            $table->string('primary_color', 7)->default('#4f46e5');
            $table->string('logo_path', 255)->nullable();
            $table->string('support_email', 191)->nullable();
            $table->string('support_whatsapp', 25)->nullable();
            $table->string('analytics_domain', 191)->nullable(); // Plausible / Matomo site domain
            $table->string('analytics_script', 255)->nullable(); // script URL
            $table->timestamps();
        });

        Schema::create('site_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40)->unique();
            $table->json('title');
            $table->json('description');
            $table->json('body');
            $table->boolean('published')->default(true);
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('hotel_name', 120);
            $table->char('country_code', 2)->nullable();
            $table->string('city', 120)->nullable();
            $table->unsignedSmallInteger('rooms')->nullable();
            $table->string('whatsapp', 25);
            $table->string('email', 191)->nullable();
            $table->text('message')->nullable(); // encrypted
            $table->string('contact_time', 60)->nullable();
            $table->string('locale', 5)->default('fr');
            $table->string('status', 20)->default('new'); // new, contacted, demo_done, trial, won, lost
            $table->string('lost_reason', 300)->nullable();
            $table->foreignUlid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable(); // encrypted
            $table->date('follow_up_on')->nullable();
            $table->foreignId('signup_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['status', 'follow_up_on']);
        });

        $now = now();
        DB::table('brand_settings')->insert(['name' => 'Assistant Hôtel', 'tagline' => json_encode(['fr' => 'Votre hôtel répond sur WhatsApp', 'en' => 'Your hotel answers on WhatsApp']), 'created_at' => $now, 'updated_at' => $now]);
        foreach (SiteDefaults::pages() as $key => $page) {
            DB::table('site_pages')->insert(['key' => $key, 'title' => json_encode($page['title']), 'description' => json_encode($page['description']), 'body' => json_encode($page['body']), 'published' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
        Schema::dropIfExists('site_pages');
        Schema::dropIfExists('brand_settings');
    }
};
