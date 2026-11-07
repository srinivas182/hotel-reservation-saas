<?php

declare(strict_types=1);

use App\Domains\Onboarding\CountryDefaults;
use App\Domains\Onboarding\LegalDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signup_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('mode', 10)->default('code'); // open, code, closed
            $table->boolean('approval_required')->default(true);
            $table->boolean('block_disposable_emails')->default(true);
            $table->boolean('emails_enabled')->default(true);
            $table->json('notify_emails')->nullable();
            $table->timestamps();
        });

        Schema::create('signup_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('note', 200)->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('country_templates', function (Blueprint $table): void {
            $table->id();
            $table->char('country_code', 2)->unique();
            $table->json('name');
            $table->char('currency', 3);
            $table->string('timezone', 64);
            $table->string('locale', 5);
            $table->json('tax_rules'); // [{name, rate, inclusive, applies_to}]
            $table->string('check_in', 5)->default('14:00');
            $table->string('check_out', 5)->default('12:00');
            $table->json('category_suggestions')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('legal_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 20); // terms, privacy, dpa
            $table->unsignedSmallInteger('version');
            $table->json('body'); // per language, Markdown
            $table->timestamp('published_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['kind', 'version']);
        });

        Schema::create('legal_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('hotel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('legal_document_id')->constrained()->restrictOnDelete();
            $table->timestamp('accepted_at');
            $table->string('ip', 45)->nullable();

            $table->unique(['user_id', 'legal_document_id']);
        });

        Schema::create('pending_signups', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('details');
            $table->foreignId('signup_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->foreignUlid('hotel_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('hotels', function (Blueprint $table): void {
            $table->string('signup_source', 20)->default('platform'); // platform, self, code
        });

        // Starting data: sign-up settings, country templates, legal texts.
        $now = now();
        DB::table('signup_settings')->insert(['mode' => 'code', 'approval_required' => true, 'block_disposable_emails' => true, 'emails_enabled' => true, 'notify_emails' => json_encode([]), 'created_at' => $now, 'updated_at' => $now]);

        foreach (CountryDefaults::all() as $country) {
            DB::table('country_templates')->insert([
                'country_code' => $country['country_code'], 'name' => json_encode($country['name']), 'currency' => $country['currency'],
                'timezone' => $country['timezone'], 'locale' => $country['locale'],
                'tax_rules' => json_encode(array_map(fn (array $t): array => [...$t, 'inclusive' => true, 'applies_to' => ['rooms', 'food_beverage', 'events']], $country['taxes'])),
                'check_in' => '14:00', 'check_out' => '12:00',
                'category_suggestions' => json_encode($country['locale'] === 'fr' ? ['Standard', 'Supérieure', 'Suite'] : ['Standard', 'Deluxe', 'Suite']),
                'active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (LegalDefaults::all() as $kind => $body) {
            DB::table('legal_documents')->insert(['kind' => $kind, 'version' => 1, 'body' => json_encode($body), 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::table('hotels', fn (Blueprint $table) => $table->dropColumn('signup_source'));
        Schema::dropIfExists('pending_signups');
        Schema::dropIfExists('legal_acceptances');
        Schema::dropIfExists('legal_documents');
        Schema::dropIfExists('country_templates');
        Schema::dropIfExists('signup_codes');
        Schema::dropIfExists('signup_settings');
    }
};
