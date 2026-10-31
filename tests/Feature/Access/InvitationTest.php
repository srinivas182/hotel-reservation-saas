<?php

declare(strict_types=1);

use App\Domains\Access\Mail\StaffInvitationMail;
use App\Domains\Access\Models\HotelInvitation;
use App\Domains\Access\StaffService;
use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    Mail::fake();
    $this->hotel = Hotel::factory()->create(['name' => 'First Class Lodge']);
    $this->owner = staffMember($this->hotel, SystemRole::Owner);
});

function inviteAndGetToken(Hotel $hotel, User $by, string $email, SystemRole $role = SystemRole::Reception): string
{
    asStaffOf($hotel, $by)
        ->post(route('staff.invitations.store'), ['email' => $email, 'role_id' => systemRole($hotel, $role)->id])
        ->assertSessionHasNoErrors();

    $mail = Mail::sent(StaffInvitationMail::class)->last();

    // Continue the test as a signed-out visitor opening the email link.
    app('auth')->forgetGuards();
    test()->flushSession();

    return Str::afterLast($mail->url, '/invitations/');
}

it('invites a new person who creates an account and joins', function (): void {
    $token = inviteAndGetToken($this->hotel, $this->owner, 'Awa@Example.com');

    test()->get(route('invitations.show', $token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'pending')->where('accountExists', false));

    test()->post(route('invitations.accept', $token), [
        'first_name' => 'Awa',
        'last_name' => 'Koné',
        'password' => 'Correct-Horse-Battery-9',
        'password_confirmation' => 'Correct-Horse-Battery-9',
    ])->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'awa@example.com')->sole();

    expect($user->email_verified_at)->not->toBeNull()
        ->and(app(StaffService::class)->roleOf($this->hotel, $user)?->name)->toBe('reception');

    $this->assertAuthenticatedAs($user);
    expect(AuditLog::query()->whereIn('event', ['staff.invited', 'staff.invitation_accepted'])->count())->toBe(2);
});

it('lets an existing account accept after signing in', function (): void {
    $existing = User::factory()->create(['email' => 'chef@example.com']);
    $token = inviteAndGetToken($this->hotel, $this->owner, 'chef@example.com', SystemRole::RestaurantBar);

    test()->get(route('invitations.show', $token))->assertInertia(fn ($page) => $page->where('accountExists', true));

    test()->actingAs($existing)->post(route('invitations.accept', $token))->assertRedirect(route('dashboard'));

    expect(app(StaffService::class)->roleOf($this->hotel, $existing)?->name)->toBe('restaurant_bar');
});

it('refuses acceptance by a different signed-in account', function (): void {
    $token = inviteAndGetToken($this->hotel, $this->owner, 'chef@example.com');

    test()->actingAs(User::factory()->create())->post(route('invitations.accept', $token))->assertForbidden();
});

it('refuses expired, revoked and reused links', function (): void {
    $expired = inviteAndGetToken($this->hotel, $this->owner, 'one@example.com');
    $this->travel(8)->days();
    test()->get(route('invitations.show', $expired))->assertInertia(fn ($page) => $page->where('state', 'expired'));
    test()->post(route('invitations.accept', $expired), [])->assertStatus(410);
    $this->travelBack();

    $revoked = inviteAndGetToken($this->hotel, $this->owner, 'two@example.com');
    $invitation = app(TenantContext::class)->run($this->hotel, fn () => HotelInvitation::query()->where('email', 'two@example.com')->sole());
    asStaffOf($this->hotel, $this->owner)->delete(route('staff.invitations.destroy', $invitation->id));
    test()->get(route('invitations.show', $revoked))->assertInertia(fn ($page) => $page->where('state', 'revoked'));

    $used = inviteAndGetToken($this->hotel, $this->owner, 'three@example.com');
    $payload = ['first_name' => 'A', 'last_name' => 'B', 'password' => 'Correct-Horse-Battery-9', 'password_confirmation' => 'Correct-Horse-Battery-9'];
    test()->post(route('invitations.accept', $used), $payload)->assertRedirect();
    auth()->logout();
    test()->post(route('invitations.accept', $used), $payload)->assertStatus(410);

    test()->get(route('invitations.show', 'not-a-real-token'))->assertInertia(fn ($page) => $page->where('state', 'invalid'));
});

it('resend replaces the link', function (): void {
    $first = inviteAndGetToken($this->hotel, $this->owner, 'resend@example.com');
    $invitation = app(TenantContext::class)->run($this->hotel, fn () => HotelInvitation::query()->sole());

    asStaffOf($this->hotel, $this->owner)->post(route('staff.invitations.resend', $invitation->id));
    $second = Str::afterLast(Mail::sent(StaffInvitationMail::class)->last()->url, '/invitations/');

    test()->get(route('invitations.show', $first))->assertInertia(fn ($page) => $page->where('state', 'invalid'));
    test()->get(route('invitations.show', $second))->assertInertia(fn ($page) => $page->where('state', 'pending'));
});

it('stops duplicates, existing members and owner invites by non-owners', function (): void {
    $manager = staffMember($this->hotel, SystemRole::Manager, ['email' => 'manager@example.com']);

    inviteAndGetToken($this->hotel, $this->owner, 'dup@example.com');
    asStaffOf($this->hotel, $this->owner)->post(route('staff.invitations.store'), ['email' => 'dup@example.com', 'role_id' => systemRole($this->hotel, SystemRole::Reception)->id])
        ->assertSessionHasErrors('email');
    asStaffOf($this->hotel, $this->owner)->post(route('staff.invitations.store'), ['email' => 'manager@example.com', 'role_id' => systemRole($this->hotel, SystemRole::Reception)->id])
        ->assertSessionHasErrors('email');
    asStaffOf($this->hotel, $manager)
        ->post(route('staff.invitations.store'), ['email' => 'new-owner@example.com', 'role_id' => systemRole($this->hotel, SystemRole::Owner)->id])
        ->assertSessionHasErrors('email');
});

it('never stores the raw token', function (): void {
    $token = inviteAndGetToken($this->hotel, $this->owner, 'hash@example.com');
    $stored = app(TenantContext::class)->run($this->hotel, fn () => HotelInvitation::query()->sole());

    expect($stored->token_hash)->toBe(hash('sha256', $token))->not->toBe($token);
});

it('keeps invitations separate between hotels', function (): void {
    $other = Hotel::factory()->create();
    $otherOwner = staffMember($other, SystemRole::Owner);
    inviteAndGetToken($other, $otherOwner, 'theirs@example.com');
    $foreign = app(TenantContext::class)->run($other, fn () => HotelInvitation::query()->sole());

    asStaffOf($this->hotel, $this->owner)->post(route('staff.invitations.resend', $foreign->id))->assertNotFound();
    asStaffOf($this->hotel, $this->owner)
        ->get(route('staff.index'))
        ->assertInertia(fn ($page) => $page->has('invitations', 0));
});

it('sends owner invitees to two-factor setup after joining', function (): void {
    $token = inviteAndGetToken($this->hotel, $this->owner, 'coowner@example.com', SystemRole::Owner);

    test()->post(route('invitations.accept', $token), [
        'first_name' => 'Co', 'last_name' => 'Owner',
        'password' => 'Correct-Horse-Battery-9', 'password_confirmation' => 'Correct-Horse-Battery-9',
    ]);

    test()->get(route('dashboard'))->assertRedirect(route('security.edit'));
});
