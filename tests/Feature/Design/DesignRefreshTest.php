<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Site\Models\BrandSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use App\Support\BrandPalette;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    $this->hotel = Hotel::factory()->create(['default_locale' => 'fr']);
    $this->owner = staffMember($this->hotel, SystemRole::Owner);
});

it('keeps text readable whatever colour a hotel picks', function (string $color): void {
    $p = BrandPalette::for($color);

    // Button text on the colour, and dark text on the soft tint (WCAG AA).
    expect(BrandPalette::contrastOf($p['light']['--primary-foreground'], $p['light']['--brand']))->toBeGreaterThanOrEqual(4.5)
        ->and(BrandPalette::contrastOf($p['light']['--brand-ink'], $p['light']['--brand-soft']))->toBeGreaterThanOrEqual(4.5)
        // The colour shows on the page, and in dark mode on the navy background.
        ->and(BrandPalette::contrastOf($p['light']['--brand'], '#f6f6fb'))->toBeGreaterThanOrEqual(3.0)
        ->and(BrandPalette::contrastOf($p['dark']['--brand'], '#0b0c1a'))->toBeGreaterThanOrEqual(5.0)
        ->and(BrandPalette::contrastOf($p['dark']['--brand-ink'], $p['dark']['--brand-soft']))->toBeGreaterThanOrEqual(4.5)
        ->and(BrandPalette::contrastOf($p['dark']['--primary-foreground'], $p['dark']['--brand']))->toBeGreaterThanOrEqual(4.5);
})->with(['#4f46e5', '#ffff00', '#ffffff', '#000000', '#0f5132', '#b45309', '#22d3ee', '#be185d', '#808080']);

it('lets the owner give the back office the hotel colour, from the first paint', function (): void {
    asStaffOf($this->hotel, $this->owner)->get(route('hotel.settings.edit'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('settings.app_color', null)->where('palette.light.--brand', '#4f46e5'))
        ->assertSee('html:root { --brand: #4f46e5;', false);

    asStaffOf($this->hotel, $this->owner)->put(route('hotel.settings.appearance'), ['app_color' => '#0F766E'])->assertSessionHasNoErrors();

    expect(app(TenantContext::class)->run($this->hotel, fn () => HotelSettings::current()->app_color))->toBe('#0f766e')
        ->and(AuditLog::query()->where('event', 'hotel.appearance_updated')->exists())->toBeTrue();
    asStaffOf($this->hotel, $this->owner)->get(route('hotel.settings.edit'))
        ->assertInertia(fn ($page) => $page->where('palette.light.--brand', '#0f766e'))
        ->assertSee('html:root { --brand: #0f766e;', false);

    // Another hotel keeps the platform colour.
    $other = Hotel::factory()->create();
    asStaffOf($other, staffMember($other, SystemRole::Owner))->get(route('hotel.settings.edit'))
        ->assertInertia(fn ($page) => $page->where('palette.light.--brand', '#4f46e5'));

    asStaffOf($this->hotel, $this->owner)->put(route('hotel.settings.appearance'), ['app_color' => 'red'])->assertSessionHasErrors('app_color');
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))->put(route('hotel.settings.appearance'), ['app_color' => '#000000'])->assertForbidden();

    asStaffOf($this->hotel, $this->owner)->put(route('hotel.settings.appearance'), ['app_color' => null])->assertSessionHasNoErrors();
    expect(app(TenantContext::class)->run($this->hotel, fn () => HotelSettings::current()->app_color))->toBeNull();
});

it('shows the platform photo beside the sign-in form, set by the super admin', function (): void {
    Storage::fake('local');
    $admin = User::factory()->platformAdmin(PlatformRole::SuperAdmin)->withTwoFactor()->create();

    $this->get(route('login'))->assertOk()->assertInertia(fn ($page) => $page->where('brand.loginPhoto', null));
    $this->get(route('brand.login-photo'))->assertNotFound();

    $this->actingAs($admin)->post(route('platform.website.brand'), ['name' => 'Assistant Hôtel', 'primary_color' => '#4f46e5', 'login_photo' => UploadedFile::fake()->image('small.jpg', 300, 200)])->assertSessionHasErrors('login_photo');
    $this->actingAs($admin)->post(route('platform.website.brand'), ['name' => 'Assistant Hôtel', 'primary_color' => '#4f46e5', 'login_photo' => UploadedFile::fake()->create('photo.pdf', 100, 'application/pdf')])->assertSessionHasErrors('login_photo');
    $this->actingAs($admin)->post(route('platform.website.brand'), ['name' => 'Assistant Hôtel', 'primary_color' => '#4f46e5', 'login_photo' => UploadedFile::fake()->image('lobby.jpg', 1600, 1000)])->assertSessionHasNoErrors();

    $path = BrandSettings::current()->login_photo_path;
    expect($path)->toStartWith('brand/login-');
    Storage::disk('local')->assertExists((string) $path);

    auth()->logout();
    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('brand.loginPhoto', fn (?string $url): bool => str_contains((string) $url, '/brand/login-photo')));
    $this->get(route('brand.login-photo'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->actingAs($admin)->post(route('platform.website.brand'), ['name' => 'Assistant Hôtel', 'primary_color' => '#4f46e5', 'remove_login_photo' => true])->assertSessionHasNoErrors();
    expect(BrandSettings::current()->login_photo_path)->toBeNull();
    Storage::disk('local')->assertMissing((string) $path);
});
