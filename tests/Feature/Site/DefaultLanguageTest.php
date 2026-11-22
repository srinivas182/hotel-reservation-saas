<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Site\Models\BrandSettings;
use App\Models\User;

function setDefaultLocale(?string $locale): void
{
    $admin = User::factory()->platformAdmin(PlatformRole::SuperAdmin)->withTwoFactor()->create();
    test()->actingAs($admin)->post(route('platform.website.brand'), ['name' => 'Assistant Hôtel', 'primary_color' => '#4f46e5', 'default_locale' => $locale ?? ''])->assertSessionHasNoErrors();
    auth()->logout();
}

it('keeps the original behaviour when no default language is chosen', function (): void {
    expect(BrandSettings::defaultLocale())->toBeNull();

    $this->get('/')->assertOk()->assertSee('<html lang="fr"', false)
        ->assertSee('<link rel="alternate" hreflang="x-default" href="'.url('/').'">', false);
    $this->get('/login', ['Accept-Language' => 'en-GB,en;q=0.9'])->assertInertia(fn ($page) => $page->where('locale', 'en'));
});

it('opens the website and the sign-in pages in English when English is the default, French one click away', function (): void {
    setDefaultLocale('en');

    $this->get('/')->assertRedirect('/en')->assertHeader('Cache-Control', 'no-store, private');
    $this->get('/en')->assertOk()
        ->assertSee('<link rel="alternate" hreflang="x-default" href="'.url('/en').'">', false)
        ->assertSee('<a href="'.url('/fr').'" hreflang="fr" lang="fr" >Français', false);
    $this->get('/fr')->assertOk()->assertSee('<html lang="fr"', false);
    $this->get('/sitemap.xml')->assertSee('<loc>'.url('/fr').'</loc>', false);

    // A French browser now starts in English, and keeps French once chosen.
    $this->get('/login', ['Accept-Language' => 'fr-FR,fr;q=0.9'])->assertInertia(fn ($page) => $page->where('locale', 'en'));
    $this->post('/locale', ['locale' => 'fr']);
    $this->get('/login')->assertInertia(fn ($page) => $page->where('locale', 'fr'));
});

it('starts new hotels in the default language', function (): void {
    setDefaultLocale('en');
    $admin = User::factory()->platformAdmin(PlatformRole::SuperAdmin)->withTwoFactor()->create();

    $this->actingAs($admin)->get(route('platform.hotels.create'))->assertInertia(fn ($page) => $page->where('defaultLocale', 'en'));

    setDefaultLocale(null);
    $this->actingAs($admin)->get(route('platform.hotels.create'))->assertInertia(fn ($page) => $page->where('defaultLocale', 'fr'));
    expect(BrandSettings::defaultLocale())->toBeNull();

    $this->actingAs($admin)->post(route('platform.website.brand'), ['name' => 'X', 'primary_color' => '#4f46e5', 'default_locale' => 'de'])->assertSessionHasErrors('default_locale');
});
