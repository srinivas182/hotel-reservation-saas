<?php

declare(strict_types=1);

use App\Domains\Billing\Models\BillingEntity;
use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Sales\Mail\ProposalMail;
use App\Domains\Sales\Models\Proposal;
use App\Domains\Sales\Roi;
use App\Domains\Site\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Billing/helpers.php';
require_once __DIR__.'/../Onboarding/helpers.php';

beforeEach(function (): void {
    Mail::fake();
    BillingEntity::query()->create(['name' => 'CNH', 'legal_name' => 'CNH', 'country_code' => 'CI', 'address' => 'Abidjan', 'email' => 'f@cnh.example', 'invoice_prefix' => 'CNH', 'is_default' => true, 'prices_include_tax' => false]);
    $this->plan = billingPlan('pro', ['restaurant'], 10, 300, 200, 45000);
    $this->plan->update(['prices' => ['XOF' => ['monthly' => 45000, 'yearly' => 450000]], 'trial_days' => 14]);
    $this->sales = User::factory()->platformAdmin(PlatformRole::Sales)->withTwoFactor()->create();
    $this->lead = Lead::query()->create(['name' => 'Moussa', 'hotel_name' => 'Hôtel Teranga', 'whatsapp' => '+221771234567', 'email' => 'moussa@teranga.example', 'rooms' => 24, 'status' => 'demo_done']);
});

function proposalPayload(array $overrides = []): array
{
    return [
        'plan_id' => test()->plan->id, 'currency' => 'XOF', 'cycle' => 'monthly', 'discount_percent' => 10, 'locale' => 'fr', 'send' => true, 'note' => 'Merci pour la démo !',
        'roi' => ['enquiries_per_day' => 12, 'missed_percent' => 30, 'conversion_percent' => 25, 'average_nights' => 2, 'night_price' => 25000], ...$overrides,
    ];
}

it('computes the return on investment plainly', function (): void {
    $roi = Roi::compute(['enquiries_per_day' => 12, 'missed_percent' => 30, 'conversion_percent' => 25, 'average_nights' => 2, 'night_price' => 25000], 40500);

    // 12 × 30% × 25% × 30 = 27 bookings; × 2 nights × 25 000 = 1 350 000 a month.
    expect($roi['bookings'])->toBe(27.0)->and($roi['revenue'])->toBe(1350000)->and($roi['multiple'])->toBe(33.3)->and($roi['payback_days'])->toBe(1);
});

it('prepares a proposal, emails it, and shows it with the return on investment', function (): void {
    $this->actingAs($this->sales)->post(route('platform.leads.proposals.store', $this->lead), proposalPayload())->assertSessionHas('proposal_link');
    $proposal = Proposal::query()->sole();

    expect($proposal->price())->toBe(40500)->and($proposal->sent_at)->not->toBeNull();
    Mail::assertQueued(ProposalMail::class, fn (ProposalMail $m): bool => $m->hasTo('moussa@teranga.example'));

    app('auth')->forgetGuards();
    $this->get('/proposal/'.$proposal->token)->assertOk()
        ->assertSee('Proposition pour Hôtel Teranga')->assertSee('Merci pour la démo !')->assertSee('27,0')->assertSee('Prix hors TVA.')->assertSee('Commencer mon essai');
    expect($proposal->fresh()->viewed_at)->not->toBeNull();

    $pdf = $this->get('/proposal/'.$proposal->token.'/pdf')->assertOk();
    expect((string) $pdf->headers->get('Content-Type'))->toContain('pdf');
});

it('starts the trial from the proposal with a linked invitation code', function (): void {
    $this->actingAs($this->sales)->post(route('platform.leads.proposals.store', $this->lead), proposalPayload(['send' => false]));
    $proposal = Proposal::query()->sole();
    app('auth')->forgetGuards();
    $this->flushSession();

    $redirect = $this->post('/proposal/'.$proposal->token.'/accept')->assertRedirect()->headers->get('Location');
    $proposal->refresh();
    expect($redirect)->toEndWith('/signup?code='.$proposal->code?->code)->and($proposal->accepted_at)->not->toBeNull()
        ->and($this->lead->fresh()->signup_code_id)->toBe($proposal->signup_code_id);

    // A second click gives the same code.
    $this->post('/proposal/'.$proposal->token.'/accept')->assertRedirect($redirect);

    publishLegalDefaults();
    signupMode('code');
    $this->post(route('signup.store'), signupPayload(['code' => $proposal->code?->code]));
    expect($this->lead->fresh()->status)->toBe('trial');
});

it('limits discounts for sales, and refuses expired or unknown proposals', function (): void {
    $this->actingAs($this->sales)->post(route('platform.leads.proposals.store', $this->lead), proposalPayload(['discount_percent' => 30]))->assertSessionHasErrors('discount_percent');
    $this->actingAs($this->sales)->post(route('platform.leads.proposals.store', $this->lead), proposalPayload(['currency' => 'GHS']))->assertSessionHasErrors('currency');

    $this->actingAs($this->sales)->post(route('platform.leads.proposals.store', $this->lead), proposalPayload());
    $proposal = Proposal::query()->sole();
    $this->travel(31)->days();
    app('auth')->forgetGuards();
    $this->get('/proposal/'.$proposal->token)->assertOk()->assertSee('Cette proposition a expiré');
    $this->post('/proposal/'.$proposal->token.'/accept')->assertStatus(410);
    $this->get('/proposal/'.str_repeat('a', 40))->assertNotFound();
});
