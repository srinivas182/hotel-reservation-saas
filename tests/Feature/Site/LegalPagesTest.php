<?php

declare(strict_types=1);

use App\Domains\Billing\Models\BillingEntity;
use App\Domains\Onboarding\LegalDefaults;
use App\Domains\Onboarding\Models\LegalDocument;
use App\Domains\Site\Models\BrandSettings;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Onboarding/helpers.php';

/*
 * 0.37.7: the legal texts are full pages of the public website, filled
 * with the issuing company's details, and the platform is CNH Global Tech.
 */
it('shows each legal text as a website page with the company details filled in', function (string $kind, string $heading): void {
    publishLegalDefaults();
    BrandSettings::current()->update(['name' => 'CNH Global Tech', 'support_email' => 'contact@cnhglobaltech.io']);
    BillingEntity::query()->create(['name' => 'CNH', 'legal_name' => 'CNH Global Tech SARL', 'country_code' => 'CI', 'address' => "Cocody, Rue des Jardins\nAbidjan", 'email' => 'billing@cnhglobaltech.io', 'invoice_prefix' => 'CNH', 'prices_include_tax' => true, 'payment_terms_days' => 15, 'is_default' => true, 'active' => true]);

    $page = $this->get('/legal/'.$kind.'?lang=en')->assertOk();

    $page->assertSee('CNH Global Tech SARL')
        ->assertSee('Cocody, Rue des Jardins, Abidjan')
        ->assertSee('mailto:contact@cnhglobaltech.io', false)
        ->assertSee($heading)
        ->assertSee('On this page')
        ->assertSee('Other legal texts')
        ->assertSee('class="top', false)      // the website header
        ->assertSee('class="site"', false)    // and footer
        ->assertDontSee('{{company}}')
        ->assertDontSee('draft for review')
        ->assertDontSee('Assistant Hôtel');

    $this->get('/legal/'.$kind.'?lang=fr')->assertOk()->assertSee('Sur cette page')->assertDontSee('projet à faire valider');
})->with([
    'terms' => ['terms', 'Applicable law and disputes'],
    'privacy' => ['privacy', 'Deleting your data'],
    'dpa' => ['dpa', 'Sub-processors'],
]);

it('says how to reach the company even before its details are entered', function (): void {
    publishLegalDefaults();
    $this->get('/legal/privacy?lang=en')->assertOk()
        ->assertSee("Abidjan, Côte d'Ivoire", false)
        ->assertSee('the address shown on our website');
});

it('renames the working brand and publishes the full legal texts once, keeping later choices', function (): void {
    $migration = require database_path('migrations/2027_01_03_000100_brand_name_and_legal_texts_v2.php');

    // The starting state of an older install.
    BrandSettings::current()->update(['name' => 'Assistant Hôtel']);
    DB::table('legal_documents')->delete();
    foreach (LegalDocument::KINDS as $kind) {
        DB::table('legal_documents')->insert(['kind' => $kind, 'version' => 1, 'body' => json_encode(['fr' => '# Ancien', 'en' => '# Old']), 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
    // The platform team already published its own privacy notice.
    DB::table('legal_documents')->insert(['kind' => 'privacy', 'version' => 2, 'body' => json_encode(['fr' => '# Nôtre', 'en' => '# Ours']), 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $migration->up();
    $migration->up(); // safe to run twice

    expect(BrandSettings::query()->first()->name)->toBe('CNH Global Tech')
        ->and(LegalDocument::current('terms')->version)->toBe(2)
        ->and(LegalDocument::current('terms')->body)->toEqual(LegalDefaults::all()['terms'])
        ->and(LegalDocument::current('privacy')->text('en'))->toBe('# Ours')
        ->and(DB::table('legal_documents')->where('kind', 'terms')->count())->toBe(2);

    // A name chosen in Platform → Website stays.
    BrandSettings::current()->update(['name' => 'Hotelia']);
    $migration->up();
    expect(BrandSettings::query()->first()->name)->toBe('Hotelia');
});
