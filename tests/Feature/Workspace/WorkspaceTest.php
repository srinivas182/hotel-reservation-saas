<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\BookingRequests\BookingRequestService;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Jobs\ReplyToGuest;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\StaffAlerts\Morning\MorningSummary;
use App\Domains\StaffAlerts\Morning\MorningSummaryText;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\Workspace\Models\ShiftChecklist;
use App\Domains\Workspace\Models\StaffPreference;
use App\Domains\Workspace\NextUp;
use App\Domains\Workspace\Tours;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../WhatsApp/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Access/helpers.php';
require_once __DIR__.'/../Bookings/helpers.php';

beforeEach(function (): void {
    configureMeta();
    Mail::fake();
    Queue::fake([ReplyToGuest::class]);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]])]);
    $this->travelTo('2026-11-01 10:00:00');
    ['hotel' => $this->hotel, 'exe' => $this->exe] = assistantHotel();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(fn () => App\Domains\WhatsApp\Models\WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => App\Domains\WhatsApp\AccountStatus::Connected, 'token' => 't']));
    $this->owner = staffMember($this->hotel, SystemRole::Owner);
    $this->reception = staffMember($this->hotel, SystemRole::Reception, ['first_name' => 'Kady']);
    $this->workspace = fn (User $user) => asStaffOf($this->hotel, $user)->get(route('hotel.today'))->viewData('page')['props'];
});

it('opens the simple dashboard for reception and the full one for the owner, as each person prefers', function (): void {
    expect(($this->workspace)($this->reception)['workspace']['simple'])->toBeTrue()
        ->and(($this->workspace)($this->owner)['workspace']['simple'])->toBeFalse();

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.workspace.mode'), ['mode' => 'full'])->assertRedirect();
    expect(($this->workspace)($this->reception)['workspace'])->toMatchArray(['simple' => false, 'mode' => 'full']);

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.workspace.mode'), ['mode' => 'default']);
    expect(($this->workspace)($this->reception)['workspace']['simple'])->toBeTrue();

    // The owner chooses which roles start simple.
    $manager = staffMember($this->hotel, SystemRole::Manager);
    asStaffOf($this->hotel, $this->owner)->put(route('hotel.workspace-settings.simple-roles'), ['roles' => [systemRole($this->hotel, SystemRole::Manager)->id]])->assertSessionHasNoErrors();
    expect(($this->workspace)($manager)['workspace']['simple'])->toBeTrue()
        ->and(($this->workspace)($this->reception)['workspace']['simple'])->toBeFalse();
    asStaffOf($this->hotel, $this->reception)->put(route('hotel.workspace-settings.simple-roles'), ['roles' => []])->assertForbidden();
});

it('keeps larger text and finished tours per person', function (): void {
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.workspace.large-text'), ['large_text' => true]);
    expect(($this->workspace)($this->reception)['workspace']['largeText'])->toBeTrue()
        ->and(($this->workspace)($this->owner)['workspace']['largeText'])->toBeFalse();

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.workspace.tour-done'), ['key' => 'phone-app']);
    expect(($this->workspace)($this->reception)['workspace']['toursDone'])->toBe(['phone-app']);

    // Finishing the welcome tour covers the feature tours; replaying brings the welcome back only.
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.workspace.tour-done'), ['key' => 'welcome']);
    expect(($this->workspace)($this->reception)['workspace']['toursDone'])->toEqualCanonicalizing(Tours::KEYS);
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.workspace.tour-replay'));
    expect(($this->workspace)($this->reception)['workspace']['toursDone'])->not->toContain('welcome')->toContain('phone-app');

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.workspace.tour-done'), ['key' => 'nope'])->assertSessionHasErrors('key');
    expect(($this->workspace)($this->reception)['workspace']['memberSince'])->toBe('2026-11-01');
});

it('lets the owner see the dashboard as another role, without changing what they may do', function (): void {
    $receptionRole = systemRole($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.workspace.view-as'), ['role' => $receptionRole->id])->assertRedirect(route('hotel.today'));
    $props = ($this->workspace)($this->owner);
    expect($props['workspace']['viewAs']['name'])->toBe($receptionRole->displayName())
        ->and($props['workspace']['simple'])->toBeTrue()
        ->and($props['auth']['permissions'])->not->toContain('staff.manage')->toContain('bookings.view')
        ->and(AuditLog::query()->where('event', 'workspace.view_as')->count())->toBe(1);

    // Only the screens change: the server still checks the owner's own rights.
    asStaffOf($this->hotel, $this->owner)->get(route('hotel.workspace-settings'))->assertOk();

    asStaffOf($this->hotel, $this->owner)->delete(route('hotel.workspace.view-as.stop'));
    expect(($this->workspace)($this->owner)['workspace']['viewAs'])->toBeNull();

    // Reception cannot preview, nor reach another hotel's roles.
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.workspace.view-as'), ['role' => $receptionRole->id])->assertForbidden();
    $other = Hotel::factory()->create();
    $foreign = App\Domains\Access\Models\Role::query()->create(['name' => 'Their role', 'hotel_id' => $other->id, 'guard_name' => 'web', 'is_system' => false]);
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.workspace.view-as'), ['role' => $foreign->id])->assertNotFound();
});

it('puts the single most urgent thing first, among what each person may do', function (): void {
    $next = fn (User $user) => ($this->in)(fn () => app(NextUp::class)->for($user));

    expect($next($this->reception)['kind'])->toBe('clear');

    // A guest in since two days ago (booked then), and today's arrival.
    $this->travelTo('2026-10-29 10:00:00');
    $late = book($this->hotel, $this->exe, '2026-10-30', '2026-11-01', phone: '+2250700000004');
    $this->travelTo('2026-11-01 10:00:00');
    $arrival = book($this->hotel, $this->exe, '2026-11-01', '2026-11-03', phone: '+2250700000003');
    expect($next($this->reception)['kind'])->toBeIn(['rooms', 'arrivals']);

    // The guest who should have left, after check-out time.
    ($this->in)(fn () => Booking::query()->whereKey($late->id)->update(['status' => BookingStatus::CheckedIn->value]));
    $this->travelTo('2026-11-01 13:00:00');
    expect($next($this->reception))->toMatchArray(['kind' => 'departure', 'tone' => 'normal']);

    // A guest waiting for staff beats everything but requests.
    webhookPost($this, [inbound('wamid.G1', from: '2250700000001', name: 'Moussa')]);
    ($this->in)(fn () => Conversation::query()->update(['needs_attention' => true, 'waiting_since' => now()->subMinutes(12)]));
    expect($next($this->reception))->toMatchArray(['kind' => 'conversation', 'tone' => 'urgent'])
        ->and($next($this->reception)['text'])->toContain('Moussa')->toContain('12');

    // A WhatsApp request first, for those who decide requests.
    $conversation = ($this->in)(fn () => Conversation::query()->with('contact.guest')->sole());
    ($this->in)(fn () => app(BookingRequestService::class)->submit($conversation, new StayRequest($this->exe->id, CarbonImmutable::parse('2026-11-12'), CarbonImmutable::parse('2026-11-14'), adults: 2), 'Moussa Traoré'));
    expect($next($this->owner)['kind'])->toBe('request')
        ->and($next($this->owner)['text'])->toContain('Moussa Traoré');

    $viewer = staffMember($this->hotel, SystemRole::ReadOnly);
    expect($next($viewer)['kind'])->not->toBe('request');

    // Today carries it.
    expect(($this->workspace)($this->owner)['nextUp']['kind'])->toBe('request');
});

it('lets the owner write shift checklists, staff tick them on Today, and the morning summary report them', function (): void {
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.checklists.store'), ['name' => 'Fermeture', 'shift' => 'closing', 'items' => ['Compter la caisse', 'Fermer le coffre']])->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.checklists.store'), ['name' => 'X', 'shift' => 'closing', 'items' => ['a']])->assertForbidden();
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.checklists.store'), ['name' => 'Bad', 'shift' => 'night', 'items' => []])->assertSessionHasErrors(['shift', 'items']);
    $list = ($this->in)(fn () => ShiftChecklist::query()->sole());

    $today = ($this->workspace)($this->reception)['checklists'][0];
    expect($today)->toMatchArray(['name' => 'Fermeture', 'done' => 0, 'total' => 2]);

    asStaffOf($this->hotel, $this->reception)->post(route('hotel.checklists.tick', $list->id), ['key' => $today['items'][0]['key'], 'done' => true])->assertRedirect();
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.checklists.tick', $list->id), ['key' => $today['items'][0]['key'], 'done' => true]); // twice: once
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.checklists.tick', $list->id), ['key' => 'not-a-task', 'done' => true])->assertSessionHasErrors('key');

    $after = ($this->workspace)($this->reception)['checklists'][0];
    expect($after['done'])->toBe(1)
        ->and($after['items'][0])->toMatchArray(['done' => true, 'by' => 'Kady', 'at' => '10:00'])
        ->and(($this->in)(fn () => app(NextUp::class)->for($this->reception)))->toMatchArray(['kind' => 'checklist']);

    // Untick, and another hotel cannot touch it.
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.checklists.tick', $list->id), ['key' => $today['items'][1]['key'], 'done' => true]);
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.checklists.tick', $list->id), ['key' => $today['items'][1]['key'], 'done' => false]);
    $other = Hotel::factory()->create();
    asStaffOf($other, staffMember($other, SystemRole::Owner))->delete(route('hotel.checklists.destroy', $list->id))->assertNotFound();

    // The next morning, the owner reads what was left undone.
    $this->travelTo('2026-11-02 07:30:00');
    $line = ($this->in)(fn () => MorningSummaryText::message(app(MorningSummary::class)->build(), 'Awa', 'fr', true));
    expect($line)->toContain('Hier, Fermeture : 1/2')->toContain('non fait : Fermer le coffre');

    // Renaming a task starts it fresh; hiding the list takes it off Today.
    asStaffOf($this->hotel, $this->owner)->put(route('hotel.checklists.update', $list->id), ['name' => 'Fermeture', 'shift' => 'closing', 'items' => ['Compter la caisse'], 'active' => false])->assertSessionHasNoErrors();
    expect(($this->workspace)($this->reception)['checklists'])->toBe([]);
    asStaffOf($this->hotel, $this->owner)->get(route('hotel.workspace-settings'))->assertInertia(fn ($page) => $page->where('checklists.0.active', false)->has('roles'));
});

it('remembers preferences per hotel', function (): void {
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.workspace.mode'), ['mode' => 'full']);
    expect(($this->in)(fn () => StaffPreference::query()->sole()->ui_mode))->toBe('full')
        ->and(StaffPreference::query()->withoutGlobalScopes()->where('hotel_id', '!=', $this->hotel->id)->count())->toBe(0);
});
