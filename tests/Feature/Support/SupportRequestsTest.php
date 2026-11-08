<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Ai\Models\AiRun;
use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Support\Mail\SupportMail;
use App\Domains\Support\Models\SavedReply;
use App\Domains\Support\Models\SupportSettings;
use App\Domains\Support\Models\SupportTicket;
use App\Domains\Support\SupportDraftAgent;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Access/helpers.php';
require_once __DIR__.'/../Billing/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';

beforeEach(function (): void {
    Mail::fake();
    Storage::fake('local');
    $this->travelTo('2026-11-09 09:00:00');
    $this->hotel = Hotel::factory()->create(['name' => 'Hôtel Le Baobab']);
    $this->other = Hotel::factory()->create();
    subscribe($this->hotel, billingPlan('pro'));
    $this->receptionist = staffMember($this->hotel, SystemRole::Reception, ['first_name' => 'Awa', 'email' => 'awa@baobab.example']);
    $this->ops = User::factory()->platformAdmin(PlatformRole::Operations)->withTwoFactor()->create(['email' => 'ops@cnh.example', 'first_name' => 'Koffi']);
});

function openRequest($test): SupportTicket
{
    asStaffOf($test->hotel, $test->receptionist)->post(route('hotel.requests.store'), [
        'subject' => 'Les modèles WhatsApp restent en attente', 'category' => 'whatsapp', 'body' => 'Depuis hier, nos modèles ne sont pas approuvés.',
        'context' => ['page' => '/hotel/whatsapp-templates', 'screen' => 'hotel/whatsapp-templates', 'browser' => 'Chrome Android'],
        'files' => [UploadedFile::fake()->image('capture.png', 400, 300)],
    ])->assertRedirect();
    // Leave the hotel session, as a platform user would be in their own browser.
    $test->flushSession();
    app('auth')->forgetGuards();

    return SupportTicket::query()->latest('id')->firstOrFail();
}

it('lets any staff member ask for help, with the context and a screenshot, and tells the team', function (): void {
    $ticket = openRequest($this);

    expect($ticket->hotel_id)->toBe($this->hotel->id)
        ->and($ticket->context)->toMatchArray(['page' => '/hotel/whatsapp-templates', 'version' => config('app.version')])
        ->and($ticket->context['role'])->toContain('reception')
        ->and($ticket->first_response_due_at->toDateTimeString())->toBe('2026-11-09 17:00:00') // Pro: 8 hours
        ->and($ticket->messages()->sole()->attachments)->toHaveCount(1)
        ->and((string) Illuminate\Support\Facades\DB::table('support_messages')->value('body'))->not->toContain('approuvés');
    Mail::assertQueued(SupportMail::class, fn (SupportMail $m): bool => $m->kind === 'team_opened' && $m->hasTo('ops@cnh.example'));

    $file = basename((string) $ticket->messages()->sole()->attachments[0]);
    asStaffOf($this->hotel, $this->receptionist)->get(route('support.attachment', [$ticket->id, $file]))->assertOk();
    asStaffOf($this->other, staffMember($this->other, SystemRole::Owner))->get(route('support.attachment', [$ticket->id, $file]))->assertNotFound();
    asStaffOf($this->other, staffMember($this->other, SystemRole::Owner))->get(route('hotel.requests.show', $ticket->id))->assertNotFound();
});

it('answers, keeps internal notes private, solves, and asks for a rating', function (): void {
    $ticket = openRequest($this);

    $this->actingAs($this->ops)->post(route('platform.support.reply', $ticket), ['body' => 'Client connu chez Meta, vérifier le nom commercial.', 'internal' => true])->assertSessionHasNoErrors();
    expect($ticket->fresh()->first_responded_at)->toBeNull();
    Mail::assertNotQueued(SupportMail::class, fn (SupportMail $m): bool => $m->kind === 'reply');

    $this->actingAs($this->ops)->post(route('platform.support.reply', $ticket), ['body' => 'Bonjour Awa, Meta vérifie le nom : 24 h maximum.', 'internal' => false])->assertSessionHasNoErrors();
    $ticket->refresh();
    expect($ticket->status)->toBe('waiting_hotel')->and($ticket->first_responded_at)->not->toBeNull()->and($ticket->assignee_id)->toBe($this->ops->id);
    Mail::assertQueued(SupportMail::class, fn (SupportMail $m): bool => $m->kind === 'reply' && $m->hasTo('awa@baobab.example'));

    $this->flushSession();
    asStaffOf($this->hotel, $this->receptionist)->get(route('hotel.requests.show', $ticket->id))
        ->assertInertia(fn ($page) => $page->has('ticket.messages', 2)->where('ticket.context', null));

    asStaffOf($this->hotel, $this->receptionist)->post(route('hotel.requests.rate', $ticket->id), ['rating' => 'good'])->assertStatus(422);
    $this->flushSession();
    app('auth')->forgetGuards();
    $this->actingAs($this->ops)->post(route('platform.support.reply', $ticket), ['body' => 'C’est approuvé !', 'internal' => false, 'solve' => true]);
    expect($ticket->fresh()->status)->toBe('solved');
    Mail::assertQueued(SupportMail::class, fn (SupportMail $m): bool => $m->kind === 'solved');

    $this->flushSession();
    asStaffOf($this->hotel, $this->receptionist)->post(route('hotel.requests.rate', $ticket->id), ['rating' => 'good', 'comment' => 'Rapide, merci'])->assertSessionHasNoErrors();
    expect($ticket->fresh()->rating)->toBe('good');

    asStaffOf($this->hotel, $this->receptionist)->post(route('hotel.requests.reply', $ticket->id), ['body' => 'En fait un modèle est refusé.']);
    expect($ticket->fresh()->status)->toBe('open');
});

it('flags late first answers and counts satisfaction', function (): void {
    $ticket = openRequest($this);
    $this->travelTo('2026-11-09 18:00:00');

    expect($ticket->fresh()->late())->toBeTrue();
    $this->actingAs($this->ops)->get(route('platform.support', ['filter' => 'late']))->assertOk()
        ->assertInertia(fn ($page) => $page->where('counts.late', 1)->has('tickets', 1));
    $this->actingAs(User::factory()->platformAdmin(PlatformRole::Sales)->withTwoFactor()->create())->get(route('platform.support'))->assertForbidden();
});

it('fills saved replies for the hotel and the person', function (): void {
    $ticket = openRequest($this);

    $this->actingAs($this->ops)->get(route('platform.support.show', $ticket))->assertInertia(fn ($page) => $page
        ->where('savedReplies', fn ($replies) => collect($replies)->contains(fn ($r) => str_contains($r['text'], 'Bonjour Awa') && str_contains($r['text'], 'Koffi'))));
    expect(SavedReply::query()->count())->toBe(2);
});

it('drafts a reply with AI, paid by the platform, never sent on its own', function (): void {
    ['hotel' => $hotel] = assistantHotel();
    $this->hotel = $hotel;
    $this->receptionist = staffMember($hotel, SystemRole::Reception, ['first_name' => 'Awa']);
    $ticket = openRequest($this);
    SupportDraftAgent::fake(['Bonjour Awa, voici comment faire : 1. Ouvrez WhatsApp → Modèles…']);
    $runs = app(TenantContext::class)->run($hotel, fn () => AiRun::query()->count());

    $this->actingAs($this->ops)->postJson(route('platform.support.draft', $ticket))->assertOk()->assertJsonPath('draft', fn ($d) => str_starts_with($d, 'Bonjour Awa'));
    expect(app(TenantContext::class)->run($hotel, fn () => AiRun::query()->count()))->toBe($runs)
        ->and($ticket->messages()->count())->toBe(1);

    SupportSettings::current()->update(['ai_drafts' => false]);
    $this->actingAs($this->ops)->postJson(route('platform.support.draft', $ticket))->assertForbidden();
});
