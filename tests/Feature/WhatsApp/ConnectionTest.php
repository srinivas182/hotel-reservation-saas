<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\WhatsAppConnector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    configureMeta();
    $this->hotel = Hotel::factory()->create(['name' => 'First Class Lodge']);
    $this->owner = staffMember($this->hotel, SystemRole::Owner);
    $this->context = app(TenantContext::class);
});

function connectPayload(array $overrides = []): array
{
    return ['code' => 'signup-code', 'waba_id' => '9000', 'phone_number_id' => '5550001', 'coexistence' => false, ...$overrides];
}

it('connects a new number: exchanges the code, registers, subscribes and stores the token encrypted', function (): void {
    fakeGraph();

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.whatsapp.connect'), connectPayload())->assertSessionHasNoErrors();

    $account = $this->context->run($this->hotel, fn () => WhatsAppAccount::query()->sole());

    expect($account->status)->toBe(AccountStatus::Connected)
        ->and($account->token)->toBe('business-token-abc')
        ->and($account->display_phone)->toBe('+225 07 00 00 00 00')
        ->and($account->quality_rating)->toBe('GREEN')
        ->and($account->pin)->toMatch('/^\d{6}$/')
        ->and(DB::table('whatsapp_accounts')->value('token'))->not->toContain('business-token-abc')
        ->and(AuditLog::query()->where('event', 'whatsapp.connected')->exists())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/5550001/register'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/9000/subscribed_apps'));
});

it('does not register numbers shared with the WhatsApp Business app', function (): void {
    fakeGraph();

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.whatsapp.connect'), connectPayload(['coexistence' => true]))->assertSessionHasNoErrors();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/register'));
    expect($this->context->run($this->hotel, fn () => WhatsAppAccount::query()->sole()->coexistence))->toBeTrue();
});

it('refuses a number already connected to another hotel', function (): void {
    fakeGraph();
    $other = Hotel::factory()->create();
    $this->context->run($other, fn () => WhatsAppAccount::query()->create([
        'waba_id' => '1', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 'x',
    ]));

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.whatsapp.connect'), connectPayload())->assertSessionHasErrors('code');
    Http::assertNothingSent();
});

it('shows Meta errors to the owner and stores nothing', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid verification code', 'code' => 100]], 400)]);

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.whatsapp.connect'), connectPayload())->assertSessionHasErrors('code');

    expect($this->context->run($this->hotel, fn () => WhatsAppAccount::query()->count()))->toBe(0)
        ->and(AuditLog::query()->where('event', 'whatsapp.connect_failed')->exists())->toBeTrue();
});

it('marks the account for reconnection when the token stops working', function (): void {
    $account = $this->context->run($this->hotel, fn () => WhatsAppAccount::query()->create([
        'waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 'old-token',
    ]));
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Session has expired', 'code' => 190]], 401)]);

    $this->artisan('whatsapp:check')->assertSuccessful();

    $account = $this->context->run($this->hotel, fn () => $account->fresh());
    expect($account->status)->toBe(AccountStatus::ReconnectRequired)
        ->and($account->last_error)->toContain('expired')
        ->and(AuditLog::query()->where('event', 'whatsapp.reconnect_required')->exists())->toBeTrue();
});

it('restores a connection once the token works again', function (): void {
    $account = $this->context->run($this->hotel, fn () => WhatsAppAccount::query()->create([
        'waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::ReconnectRequired, 'token' => 'new-token',
    ]));
    fakeGraph();

    expect($this->context->run($this->hotel, fn () => app(WhatsAppConnector::class)->check($account)))->toBeTrue()
        ->and($account->status)->toBe(AccountStatus::Connected)
        ->and($account->quality_rating)->toBe('GREEN');
});

it('disconnects after password confirmation and forgets the token', function (): void {
    fakeGraph();
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.whatsapp.connect'), connectPayload());

    asStaffOf($this->hotel, $this->owner)->delete(route('hotel.whatsapp.disconnect'))->assertRedirect(route('password.confirm'));
    asStaffOf($this->hotel, $this->owner)->withSession(['auth.password_confirmed_at' => time()])->delete(route('hotel.whatsapp.disconnect'));

    $account = $this->context->run($this->hotel, fn () => WhatsAppAccount::query()->sole());
    expect($account->status)->toBe(AccountStatus::Disconnected)->and($account->token)->toBeNull();
});

it('lets only owners manage the connection and never sends the token to the browser', function (): void {
    fakeGraph();
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.whatsapp.connect'), connectPayload());

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Manager))->get(route('hotel.whatsapp'))->assertForbidden();
    asStaffOf($this->hotel, $this->owner)->get(route('hotel.whatsapp'))
        ->assertOk()
        ->assertDontSee('business-token-abc')
        ->assertInertia(fn ($page) => $page->where('account.status', 'connected')->where('signup.ready', true));
});

it('shows every connected number to platform staff', function (): void {
    fakeGraph();
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.whatsapp.connect'), connectPayload());
    $admin = App\Models\User::factory()->platformAdmin()->withTwoFactor()->create();
    $this->flushSession();

    $this->actingAs($admin)->get(route('platform.whatsapp'))
        ->assertOk()
        ->assertDontSee('business-token-abc')
        ->assertInertia(fn ($page) => $page->has('accounts', 1)->where('accounts.0.hotel', 'First Class Lodge')->where('config.app_secret_set', true));
});
