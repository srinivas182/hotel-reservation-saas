<?php

declare(strict_types=1);

use App\Domains\Billing\Models\BillingEntity;
use App\Domains\Site\Models\BrandSettings;
use App\Domains\Site\Models\SitePage;
use App\Models\User;
use Symfony\Component\Finder\Finder;

require_once __DIR__.'/../Billing/helpers.php';

it('shows the website in French at / and in English at /en, with alternate links', function (): void {
    $this->get('/')->assertOk()->assertSee('<html lang="fr">', false)->assertSee('Demander une démo')
        ->assertSee('hreflang="en" href="'.url('/en').'"', false);
    $this->get('/en')->assertOk()->assertSee('<html lang="en">', false)->assertSee('Ask for a demo');

    foreach (['features', 'countries', 'faq', 'pricing', 'demo'] as $page) {
        $this->get('/'.$page)->assertOk();
        $this->get('/en/'.$page)->assertOk()->assertSee('rel="canonical" href="'.url('/en/'.$page).'"', false);
    }

    SitePage::query()->where('key', 'faq')->update(['published' => false]);
    $this->get('/faq')->assertNotFound();

    $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
    expect($sitemap)->toContain(url('/en/pricing'))->toContain(url('/pricing'));
});

it('builds the price list from billing, in the visitor’s currency', function (): void {
    BillingEntity::query()->create(['name' => 'CNH', 'legal_name' => 'CNH', 'country_code' => 'CI', 'address' => 'Abidjan', 'email' => 'f@cnh.example', 'invoice_prefix' => 'CNH', 'is_default' => true, 'prices_include_tax' => false]);
    $pro = billingPlan('pro', ['restaurant'], 10, 300, 200, 45000);
    $pro->update(['name' => ['fr' => 'Pro', 'en' => 'Pro'], 'prices' => ['XOF' => ['monthly' => 45000, 'yearly' => 450000], 'GHS' => ['monthly' => 95000, 'yearly' => 950000]]]);
    billingPlan('fcfa-only', [], 3, 100, 50, 25000)->update(['name' => ['fr' => 'Essentiel', 'en' => 'Essential']]);

    $this->get('/pricing')->assertOk()->assertSee('Pro')->assertSee('Essentiel')->assertSee('Prix hors TVA.')->assertSee('Restaurant et bar');
    $this->withHeader('CF-IPCountry', 'GH')->get('/en/pricing')->assertSee('GHS')->assertDontSee('Essential');
    $this->get('/en/pricing?currency=XOF')->assertSee('Essential');
});

it('takes demo requests, and stops spam', function (): void {
    $payload = ['name' => 'Moussa Koné', 'hotel_name' => 'Hôtel Teranga', 'country_code' => 'SN', 'city' => 'Dakar', 'rooms' => 24, 'whatsapp' => '+221 77 123 45 67', 'message' => 'Démo la semaine prochaine ?'];

    $this->post('/demo', $payload)->assertRedirect('/demo');
    $this->post('/demo', [...$payload, 'website' => 'http://spam'])->assertRedirect('/demo');
    $this->post('/en/demo', [...$payload, 'whatsapp' => 'not a phone'])->assertSessionHasErrors('whatsapp');

    $lead = App\Domains\Site\Models\Lead::query()->sole();
    expect($lead->hotel_name)->toBe('Hôtel Teranga')->and($lead->status)->toBe('new')->and($lead->locale)->toBe('fr')
        ->and((string) Illuminate\Support\Facades\DB::table('leads')->value('message'))->not->toContain('semaine');

    foreach (range(1, 3) as $i) {
        $this->post('/demo', $payload);
    }
    $this->post('/demo', $payload)->assertStatus(429);
});

it('names the product from the brand settings everywhere, never "Laravel"', function (): void {
    BrandSettings::current()->update(['name' => 'Réceptio']);

    expect(config('app.name'))->toBe('Réceptio')->and(config('mail.from.name'))->toBe('Réceptio');
    $this->actingAs(User::factory()->create())->get(route('profile.edit'))->assertInertia(fn ($page) => $page->where('name', 'Réceptio'));
    $this->app['auth']->forgetGuards();
    $this->get('/en')->assertSee('Réceptio');

    $visible = [];
    // Shown text only: quoted strings and page text (generated route helpers and comments aside).
    foreach ((new Finder)->files()->in([resource_path('js'), resource_path('views')])->exclude(['actions', 'routes', 'wayfinder'])->name(['*.tsx', '*.ts', '*.blade.php']) as $file) {
        if (preg_match('/([\'"`>]\s*Laravel\b|\bLaravel\s*[\'"`<])/', (string) file_get_contents($file->getRealPath())) === 1) {
            $visible[] = $file->getRelativePathname();
        }
    }
    expect($visible)->toBe([]);
});
