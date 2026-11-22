<?php

declare(strict_types=1);

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Platform\Http\Controllers\ManualWhatsAppController;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Graph\GraphClient;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    configureMeta();
    $this->hotel = Hotel::factory()->create(['name' => 'First Class Lodge']);
    $this->admin = User::factory()->platformAdmin()->withTwoFactor()->create();
    $this->payload = ['phone_number_id' => '1530088293513718', 'waba_id' => '1445444227749781', 'token' => 'EAAG-system-user-token-0123456789'];
});

it('connects Meta’s test number with a system-user token, without Embedded Signup', function (): void {
    fakeGraph();

    $this->actingAs($this->admin)
        ->post(route('platform.hotels.whatsapp.manual', $this->hotel), $this->payload)
        ->assertSessionHasNoErrors();

    $account = app(TenantContext::class)->run($this->hotel, fn () => WhatsAppAccount::query()->sole());

    expect($account->status)->toBe(AccountStatus::Connected)
        ->and($account->phone_number_id)->toBe('1530088293513718')
        ->and($account->token)->toBe('EAAG-system-user-token-0123456789')
        ->and($account->pin)->toBeNull()
        ->and(DB::table('whatsapp_accounts')->value('token'))->not->toContain('system-user-token')
        ->and(AuditLog::query()->where('event', 'whatsapp.connected_manually')->exists())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/1445444227749781/subscribed_apps')
        && $request->hasHeader('Authorization', 'Bearer EAAG-system-user-token-0123456789'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/register') || str_contains($request->url(), 'oauth'));
});

it('shows Meta’s reason when the token is refused and stores nothing', function (): void {
    fakeGraph(['phone' => Http::response(['error' => ['message' => 'Invalid OAuth access token', 'code' => 190]], 401)]);

    $this->actingAs($this->admin)
        ->post(route('platform.hotels.whatsapp.manual', $this->hotel), $this->payload)
        ->assertSessionHasErrors('token');

    expect(app(TenantContext::class)->run($this->hotel, fn () => WhatsAppAccount::query()->count()))->toBe(0);
});

it('is for super admins only, and off in production unless enabled', function (): void {
    fakeGraph();
    $support = User::factory()->platformAdmin(PlatformRole::Operations)->withTwoFactor()->create();

    $this->actingAs($support)->post(route('platform.hotels.whatsapp.manual', $this->hotel), $this->payload)->assertForbidden();

    expect(ManualWhatsAppController::allowed($this->admin))->toBeTrue();

    app()->detectEnvironment(fn (): string => 'production');
    expect(ManualWhatsAppController::allowed($this->admin))->toBeFalse();

    config(['whatsapp.manual_connect' => 'true']);
    expect(ManualWhatsAppController::allowed($this->admin))->toBeTrue()
        ->and(ManualWhatsAppController::allowed($support))->toBeFalse();
    app()->detectEnvironment(fn (): string => 'testing');
});

it('offers the form on the hotel page only to those allowed', function (): void {
    $this->actingAs($this->admin)->get(route('platform.hotels.show', $this->hotel))
        ->assertInertia(fn ($page) => $page->where('can.manualWhatsApp', true));
});

it('sends only simulated numbers to the simulator when simulation is on', function (): void {
    config(['simulation.enabled' => true, 'whatsapp.simulation_graph_url' => 'https://app.test/simulation/graph', 'whatsapp.graph_url' => 'https://graph.facebook.com']);
    $graph = app(GraphClient::class);

    expect($graph->url('SIM-5550001/messages'))->toBe('https://app.test/simulation/graph/v23.0/SIM-5550001/messages')
        ->and($graph->url('sim-media-1'))->toBe('https://app.test/simulation/graph/v23.0/sim-media-1')
        ->and($graph->url('1530088293513718/messages'))->toBe('https://graph.facebook.com/v23.0/1530088293513718/messages')
        ->and($graph->url('oauth/access_token'))->toBe('https://graph.facebook.com/v23.0/oauth/access_token');

    config(['simulation.enabled' => false]);
    expect($graph->url('SIM-5550001/messages'))->toBe('https://graph.facebook.com/v23.0/SIM-5550001/messages');
});

it('opens the hotel page when its history has several entries by people', function (): void {
    fakeGraph();
    foreach (['first', 'second'] as $note) {
        App\Domains\Audit\Audit::record('test.'.$note, 'Test entry', subject: $this->hotel, causer: $this->admin);
    }
    AuditLog::query()->update(['hotel_id' => $this->hotel->id]);

    $this->actingAs($this->admin)->get(route('platform.hotels.show', $this->hotel))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('history', fn ($history) => collect($history)->where('by', $this->admin->name)->count() >= 2));
});
