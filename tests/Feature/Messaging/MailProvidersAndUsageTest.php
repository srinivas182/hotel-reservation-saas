<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Messaging\Email\EmailCategory;
use App\Domains\Messaging\Email\Models\EmailMessage;
use App\Domains\Messaging\Email\Models\MailProvider;
use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Access/helpers.php';

function providerPayload(array $overrides = []): array
{
    return [
        'name' => 'Brevo main',
        'driver' => 'brevo',
        'from_address' => 'bookings@platform.test',
        'enabled' => true,
        'priority' => 1,
        'credentials' => ['username' => 'smtp-user', 'password' => 'super-secret-key'],
        ...$overrides,
    ];
}

beforeEach(function (): void {
    $this->admin = User::factory()->platformAdmin()->withTwoFactor()->create();
});

it('stores credentials encrypted and never returns them', function (): void {
    $this->actingAs($this->admin)->post(route('platform.mail-providers.store'), providerPayload())->assertSessionHasNoErrors();

    $raw = DB::table('mail_providers')->value('credentials');

    expect($raw)->not->toContain('super-secret-key')
        ->and(MailProvider::query()->sole()->credentials['password'])->toBe('super-secret-key');

    $response = $this->actingAs($this->admin)->get(route('platform.mail-providers.index'));
    $response->assertOk()->assertDontSee('super-secret-key')
        ->assertInertia(fn ($page) => $page->where('providers.0.saved.password.present', true));
});

it('keeps a saved secret when the field is left blank', function (): void {
    $this->actingAs($this->admin)->post(route('platform.mail-providers.store'), providerPayload());
    $provider = MailProvider::query()->sole();

    $this->actingAs($this->admin)
        ->put(route('platform.mail-providers.update', $provider), providerPayload(['name' => 'Renamed', 'credentials' => ['username' => 'smtp-user', 'password' => '']]))
        ->assertSessionHasNoErrors();

    expect($provider->refresh()->credentials['password'])->toBe('super-secret-key')
        ->and($provider->name)->toBe('Renamed');
});

it('requires credentials for a new provider', function (): void {
    $this->actingAs($this->admin)
        ->post(route('platform.mail-providers.store'), providerPayload(['credentials' => []]))
        ->assertSessionHasErrors(['credentials.username', 'credentials.password']);
});

it('limits providers to super admins', function (): void {
    $support = User::factory()->platformAdmin(PlatformRole::Support)->withTwoFactor()->create();

    $this->actingAs($support)->get(route('platform.mail-providers.index'))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('platform.mail-providers.index'))->assertForbidden();
});

it('shows each hotel only its own email usage', function (): void {
    $hotel = Hotel::factory()->create();
    $other = Hotel::factory()->create();
    $manager = staffMember($hotel, SystemRole::Manager);

    foreach ([[$hotel, 'guest'], [$hotel, 'guest'], [$other, 'guest'], [$other, 'marketing']] as [$owner, $category]) {
        EmailMessage::query()->create([
            'hotel_id' => $owner->id, 'category' => $category, 'recipients' => ['g@example.com'], 'status' => 'sent',
        ]);
    }

    asStaffOf($hotel, $manager)
        ->get(route('hotel.usage'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hotel/usage')
            ->where('categories', fn ($rows): bool => collect($rows)->firstWhere('category', 'guest')['sent'] === 2
                && collect($rows)->firstWhere('category', 'marketing')['sent'] === 0)
            ->where('limit', null));

    asStaffOf($hotel, staffMember($hotel, SystemRole::Reception))->get(route('hotel.usage'))->assertForbidden();
});

it('shows platform-wide totals to super admins', function (): void {
    EmailMessage::query()->create(['hotel_id' => null, 'category' => EmailCategory::System, 'recipients' => ['x@example.com'], 'status' => 'sent']);

    $this->actingAs($this->admin)
        ->get(route('platform.mail-providers.index'))
        ->assertInertia(fn ($page) => $page->where('totals', fn ($rows): bool => collect($rows)->sum('sent') === 1));
});
