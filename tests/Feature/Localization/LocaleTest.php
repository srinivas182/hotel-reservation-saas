<?php

declare(strict_types=1);

use App\Domains\Access\Mail\StaffInvitationMail;
use App\Domains\Access\SystemRole;
use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../Access/helpers.php';

it('uses the user\'s own language first', function (): void {
    $hotel = Hotel::factory()->create(['default_locale' => 'en']);
    $user = staffMember($hotel, SystemRole::Manager, ['preferred_locale' => 'fr']);

    asStaffOf($hotel, $user)->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('locale', 'fr'));
});

it('falls back to the hotel default, then the browser, then English', function (): void {
    $frHotel = Hotel::factory()->create(['default_locale' => 'fr']);
    $user = staffMember($frHotel, SystemRole::Reception, ['preferred_locale' => null]);

    asStaffOf($frHotel, $user)->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('locale', 'fr'));

    app('auth')->forgetGuards();
    $this->flushSession();

    $this->withHeader('Accept-Language', 'fr-CI,fr;q=0.9,en;q=0.5')
        ->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('locale', 'fr'));

    $this->withHeader('Accept-Language', 'de-DE')
        ->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('locale', 'en'));
});

it('lets people switch language and remembers it on their account', function (): void {
    $user = User::factory()->create(['preferred_locale' => null]);

    $this->actingAs($user)->post(route('locale.update'), ['locale' => 'fr'])->assertRedirect();

    expect($user->refresh()->preferred_locale)->toBe('fr');

    $this->actingAs($user)->post(route('locale.update'), ['locale' => 'xx'])->assertSessionHasErrors('locale');
});

it('remembers a visitor\'s choice for the session', function (): void {
    $this->post(route('locale.update'), ['locale' => 'fr']);

    $this->withHeader('Accept-Language', 'en')->get(route('login'))->assertInertia(fn ($page) => $page->where('locale', 'fr'));
});

it('translates server messages', function (): void {
    $hotel = Hotel::factory()->create(['default_locale' => 'fr']);
    $owner = staffMember($hotel, SystemRole::Owner, ['preferred_locale' => 'fr']);

    asStaffOf($hotel, $owner)
        ->post(route('staff.invitations.store'), ['email' => 'not-an-email', 'role_id' => systemRole($hotel, SystemRole::Reception)->id])
        ->assertSessionHasErrors(['email' => 'Le champ adresse e-mail doit être une adresse e-mail valide.']);
});

it('sends emails in French', function (): void {
    Mail::fake();
    Notification::fake();

    $hotel = Hotel::factory()->create(['name' => 'First Class Lodge', 'default_locale' => 'fr']);
    $owner = staffMember($hotel, SystemRole::Owner);

    asStaffOf($hotel, $owner)->post(route('staff.invitations.store'), [
        'email' => 'awa@example.com',
        'role_id' => systemRole($hotel, SystemRole::Reception)->id,
    ]);

    Mail::assertSent(StaffInvitationMail::class, function (StaffInvitationMail $mail): bool {
        $mail->locale ??= 'en';

        return $mail->locale === 'fr'
            && str_contains($mail->render(), 'Accepter l’invitation');
    });

    app('auth')->forgetGuards();
    $frUser = User::factory()->create(['preferred_locale' => 'fr']);
    $this->post(route('password.email'), ['email' => $frUser->email]);

    Notification::assertSentTo($frUser, ResetPassword::class, fn ($notification, $channels, $notifiable, $locale): bool => $locale === 'fr');
});

it('fails the translation check when a language is missing a key', function (): void {
    $path = lang_path('zz.json');
    file_put_contents($path, json_encode(['Settings' => 'Réglages']));

    try {
        $this->artisan('translations:check')->assertFailed();
    } finally {
        unlink($path);
    }

    $this->artisan('translations:check')->assertSuccessful();
});
