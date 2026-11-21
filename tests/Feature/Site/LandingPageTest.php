<?php

declare(strict_types=1);

use App\Domains\Site\Models\BrandSettings;
use App\Domains\Site\Models\SitePage;

require_once __DIR__.'/../Billing/helpers.php';

it('offers a language selector, not a menu link, on every page', function (): void {
    $page = $this->get('/')->assertOk();

    // The selector shows the current language and lists both, with the current one marked.
    $page->assertSee('<details class="lang" data-close>', false)
        ->assertSee('<span class="sr">Langue: Français</span>', false)
        ->assertSee('<a href="'.url('/en').'" hreflang="en" lang="en" >English', false)
        ->assertSee('hreflang="fr" lang="fr"  aria-current="true" >Français', false);

    $this->get('/en/pricing')->assertOk()->assertSee('<span class="sr">Language: English</span>', false)
        ->assertSee('<a href="'.url('/pricing').'" hreflang="fr" lang="fr" >Français', false);
});

it('shows the sections of the landing page, with the plans and the highlighted title', function (): void {
    billingPlan('pro', ['restaurant'], 10, 300, 200, 45000)->update(['name' => ['fr' => 'Pro', 'en' => 'Pro'], 'is_default' => true]);

    $this->get('/')->assertOk()
        ->assertSee('Votre hôtel répond sur WhatsApp, <em>jour et nuit</em>', false)
        ->assertSeeInOrder(['Comment ça marche', 'Voyez-le en action', 'Pour toute l’équipe', 'Tout au même endroit', 'Pensé pour l’Afrique de l’Ouest', 'Des prix simples, dans votre monnaie', 'Le plus choisi'])
        ->assertSee('"@type":"SoftwareApplication"', false);

    $this->get('/en')->assertOk()->assertSeeInOrder(['How it works', 'See it in action', 'Simple prices, in your currency', 'Most chosen']);
});

it('escapes the editable title, and sends the chat button to the sales WhatsApp number', function (): void {
    SitePage::query()->where('key', 'home')->update(['title' => json_encode(['fr' => '<script>x</script>, toujours', 'en' => 'Hello'])]);

    $this->get('/')->assertOk()->assertDontSee('<script>x</script>', false)
        ->assertSee('&lt;script&gt;x&lt;/script&gt;, <em>toujours</em>', false)
        ->assertSee('class="chat-fab" aria-label="Écrivez-nous" href="/demo"', false);

    BrandSettings::current()->update(['support_whatsapp' => '+225 07 00 00 00 01']);
    $this->get('/')->assertSee('href="https://wa.me/2250700000001?text=', false);
    $this->get('/demo')->assertDontSee('class="chat-fab"', false);
});
