<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Operations\OpsIncident;
use App\Domains\Status\Mail\StatusMail;
use App\Domains\Status\Models\StatusNotice;
use App\Domains\Status\Models\StatusSubscriber;
use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    Mail::fake();
    Cache::flush();
    $this->ops = User::factory()->platformAdmin(PlatformRole::Operations)->withTwoFactor()->create();
});

function postNotice($test, array $overrides = []): void
{
    $test->actingAs($test->ops)->post(route('platform.status.store'), [
        'title' => ['fr' => 'Retards WhatsApp en Côte d’Ivoire', 'en' => 'WhatsApp delays in Côte d’Ivoire'],
        'body' => ['fr' => 'Meta signale des retards.', 'en' => 'Meta reports delays.'],
        'level' => 'degraded', 'components' => ['whatsapp'], 'countries' => ['CI'], ...$overrides,
    ])->assertSessionHasNoErrors();
}

it('shows every service working, in French and English', function (): void {
    $this->get('/status')->assertOk()->assertSee('Tout fonctionne.')->assertSee('Messages WhatsApp (Meta)');
    $this->get('/en/status')->assertOk()->assertSee('Everything is working.');
});

it('follows the operations checks automatically', function (): void {
    OpsIncident::query()->create(['key' => 'ai_failures', 'title' => 'AI providers are failing', 'detail' => 'x', 'opened_at' => now(), 'last_notified_at' => now()]);

    $this->get('/en/status')->assertSee('Slower than usual')->assertDontSee('Everything is working.');
});

it('shows notices to the countries concerned, on the page and in the app', function (): void {
    postNotice($this);
    $this->app['auth']->forgetGuards();

    $this->get('/en/status?country=CI')->assertSee('WhatsApp delays in Côte d’Ivoire', false);
    $this->get('/en/status?country=SN')->assertDontSee('WhatsApp delays in Côte d’Ivoire', false)->assertSee('Everything is working.');

    $ci = Hotel::factory()->create(['country_code' => 'CI']);
    $sn = Hotel::factory()->create(['country_code' => 'SN']);
    asStaffOf($ci, staffMember($ci, SystemRole::Reception))->get(route('hotel.today'))->assertInertia(fn ($page) => $page->has('statusNotices', 1));
    asStaffOf($sn, staffMember($sn, SystemRole::Reception))->get(route('hotel.today'))->assertInertia(fn ($page) => $page->has('statusNotices', 0));
});

it('emails confirmed subscribers about updates, after a double opt-in', function (): void {
    $this->post('/status/subscribe', ['email' => 'Owner@Hotel.ci', 'country_code' => 'CI'])->assertRedirect('/status');
    $subscriber = StatusSubscriber::query()->sole();
    expect($subscriber->email)->toBe('owner@hotel.ci')->and($subscriber->confirmed_at)->toBeNull();
    Mail::assertQueued(StatusMail::class, fn (StatusMail $m): bool => $m->noticeId === null);

    postNotice($this);
    Mail::assertQueued(StatusMail::class, 1); // not confirmed yet: only the confirmation

    $this->get('/status/confirm/'.$subscriber->token)->assertRedirect('/status');
    $notice = StatusNotice::query()->sole();
    $this->actingAs($this->ops)->post(route('platform.status.update', $notice), ['body' => ['fr' => 'Résolu.', 'en' => 'Resolved.'], 'state' => 'resolved'])->assertSessionHasNoErrors();

    expect($notice->fresh()->resolved_at)->not->toBeNull();
    Mail::assertQueued(StatusMail::class, fn (StatusMail $m): bool => $m->noticeId === $notice->id && $m->update === 'Résolu.');

    $this->get('/status/unsubscribe/'.$subscriber->token);
    expect(StatusSubscriber::query()->count())->toBe(0);
    $this->app['auth']->forgetGuards();
    $this->get('/status')->assertSee('Retards WhatsApp');
});

it('lets operations post notices, not sales', function (): void {
    $this->actingAs(User::factory()->platformAdmin(PlatformRole::Sales)->withTwoFactor()->create())->get(route('platform.status'))->assertForbidden();
    $this->actingAs($this->ops)->get(route('platform.status'))->assertOk()->assertInertia(fn ($page) => $page->has('components', 5));
    $this->actingAs($this->ops)->post(route('platform.status.store'), ['title' => ['fr' => 'x', 'en' => 'x'], 'body' => ['fr' => 'x', 'en' => 'x'], 'level' => 'outage', 'components' => []])->assertSessionHasErrors('components');
});
