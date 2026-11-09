<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Product\Mail\IdeaUpdateMail;
use App\Domains\Product\Mail\WhatsNewDigestMail;
use App\Domains\Product\Models\Idea;
use App\Domains\Product\Models\ReleaseNote;
use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    Mail::fake();
    $this->travelTo('2026-12-01 09:00:00');
    $this->hotel = Hotel::factory()->create();
    $this->owner = staffMember($this->hotel, SystemRole::Owner, ['email' => 'owner@baobab.example']);
    $this->reception = staffMember($this->hotel, SystemRole::Reception, ['email' => 'desk@baobab.example']);
    $this->ops = User::factory()->platformAdmin(PlatformRole::Operations)->withTwoFactor()->create();
});

function note(array $overrides = []): ReleaseNote
{
    return ReleaseNote::query()->create(['title' => ['fr' => 'Démo en direct', 'en' => 'Live demo'], 'body' => ['fr' => 'Montrez **l’assistant**.', 'en' => 'Show the assistant.'], 'try_path' => '/hotel/demo', 'published_at' => now()->subDays(5), ...$overrides]);
}

it('shows what is new with a dot until it is read', function (): void {
    note();
    note(['title' => ['fr' => 'Brouillon', 'en' => 'Draft'], 'published_at' => null]);

    asStaffOf($this->hotel, $this->reception)->get(route('hotel.today'))->assertInertia(fn ($page) => $page->where('whatsNewUnread', true));
    asStaffOf($this->hotel, $this->reception)->get(route('whats-new'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('notes', 1)->where('notes.0.new', true)->where('notes.0.try', '/hotel/demo')->where('notes.0.html', fn ($h) => str_contains($h, '<strong>')));
    asStaffOf($this->hotel, $this->reception)->get(route('hotel.today'))->assertInertia(fn ($page) => $page->where('whatsNewUnread', false));
});

it('emails last month’s notes to owners and managers who want them, once a month', function (): void {
    note(['published_at' => '2026-11-10 10:00:00']);
    note(['published_at' => '2026-10-10 10:00:00', 'title' => ['fr' => 'Ancienne', 'en' => 'Old']]);
    $other = Hotel::factory()->create();
    staffMember($other, SystemRole::Owner, ['email' => 'off@other.example'])->forceFill(['product_emails' => false])->save();

    $this->artisan('whats-new:digest')->assertSuccessful();

    Mail::assertQueued(WhatsNewDigestMail::class, fn (WhatsNewDigestMail $m): bool => $m->hasTo('owner@baobab.example') && count($m->noteIds) === 1);
    Mail::assertNotQueued(WhatsNewDigestMail::class, fn (WhatsNewDigestMail $m): bool => $m->hasTo('desk@baobab.example') || $m->hasTo('off@other.example'));

    $this->get(URL::signedRoute('product-emails.off', ['user' => $this->owner->id]))->assertOk();
    expect($this->owner->fresh()->product_emails)->toBeFalse();
    $this->get(route('product-emails.off', ['user' => $this->reception->id]))->assertForbidden();
});

it('lets hotels suggest and vote, shows similar ideas first, and counts each person once', function (): void {
    asStaffOf($this->hotel, $this->owner)->post(route('ideas.store'), ['title' => 'Export des réservations vers Excel', 'category' => 'bookings'])->assertSessionHasNoErrors();
    $idea = Idea::query()->sole();
    expect($idea->votes_count)->toBe(1)->and($idea->hotel_id)->toBe($this->hotel->id);

    asStaffOf($this->hotel, $this->reception)->getJson(route('ideas.similar', ['q' => 'exporter les réservations']))->assertJsonPath('ideas.0.id', $idea->id);
    asStaffOf($this->hotel, $this->reception)->post(route('ideas.vote', $idea));
    expect($idea->fresh()->votes_count)->toBe(2);
    asStaffOf($this->hotel, $this->reception)->post(route('ideas.vote', $idea));
    expect($idea->fresh()->votes_count)->toBe(1);

    asStaffOf($this->hotel, $this->reception)->get(route('ideas'))->assertInertia(fn ($page) => $page->where('ideas.0.title', 'Export des réservations vers Excel')->where('ideas.0.voted', false));
});

it('lets the platform plan, answer and merge ideas, and tells the voters', function (): void {
    asStaffOf($this->hotel, $this->owner)->post(route('ideas.store'), ['title' => 'Export Excel des réservations', 'category' => 'bookings']);
    asStaffOf($this->hotel, $this->reception)->post(route('ideas.store'), ['title' => 'Exporter les réservations en tableur', 'category' => 'bookings']);
    [$keep, $duplicate] = Idea::query()->orderBy('id')->get()->all();
    $this->flushSession();
    app('auth')->forgetGuards();

    $this->actingAs($this->ops)->put(route('platform.product.ideas.decide', $duplicate), ['status' => 'new', 'merge_into' => $keep->id])->assertSessionHasNoErrors();
    expect($keep->fresh()->votes_count)->toBe(2)->and($duplicate->fresh()->merged_into_id)->toBe($keep->id);

    $this->actingAs($this->ops)->put(route('platform.product.ideas.decide', $keep), ['status' => 'planned', 'reply' => 'Prévu pour janvier.'])->assertSessionHasNoErrors();
    Mail::assertQueued(IdeaUpdateMail::class, 2);
    $this->actingAs(User::factory()->platformAdmin(PlatformRole::Finance)->withTwoFactor()->create())->get(route('platform.product'))->assertForbidden();
});

it('lets the platform write and publish release notes', function (): void {
    $this->actingAs($this->ops)->post(route('platform.product.notes.store'), ['version' => '0.17.7', 'title' => ['fr' => 'Idées', 'en' => 'Ideas'], 'body' => ['fr' => 'Votez.', 'en' => 'Vote.'], 'try_path' => '/ideas', 'publish' => true])->assertSessionHasNoErrors();
    expect(ReleaseNote::query()->published()->count())->toBe(1);
    $this->actingAs($this->ops)->post(route('platform.product.notes.store'), ['title' => ['fr' => 'x', 'en' => 'x'], 'body' => ['fr' => 'x', 'en' => 'x'], 'try_path' => 'https://evil.example'])->assertSessionHasErrors('try_path');
});
