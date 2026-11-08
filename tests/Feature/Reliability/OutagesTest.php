<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Billing\Models\BillingEntity;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Payments\PaymentCheckout;
use App\Domains\Billing\Payments\PaymentGateway;
use App\Domains\Conversations\Jobs\SendMessage;
use App\Domains\Conversations\MessageStatus;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Conversations\Models\WhatsAppContact;
use App\Domains\Messaging\Usage\UsageRecord;
use App\Domains\Onboarding\Mail\OnboardingMail;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Graph\GraphException;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Access/helpers.php';
require_once __DIR__.'/../WhatsApp/helpers.php';
require_once __DIR__.'/../Billing/helpers.php';

beforeEach(function (): void {
    configureMeta();
    $this->hotel = Hotel::factory()->create(['currency' => 'XOF']);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
});

it('keeps a reply waiting while Meta is down, sends it once when Meta is back', function (): void {
    $message = ($this->in)(function () {
        WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 'hotel-token']);
        $contact = WhatsAppContact::query()->create(['wa_id' => '2250700000001']);
        $conversation = Conversation::query()->create(['whatsapp_contact_id' => $contact->id, 'status' => 'open', 'last_message_at' => now()]);

        return Message::query()->create(['conversation_id' => $conversation->id, 'direction' => 'out', 'type' => 'text', 'body' => 'Bonjour', 'status' => MessageStatus::Queued, 'source' => 'staff', 'sent_at' => now()]);
    });

    Http::fakeSequence('graph.facebook.com/*')->push(['error' => ['message' => 'Service unavailable', 'code' => 2]], 503)->push(['messages' => [['id' => 'wamid.OK']]], 200);
    $job = new SendMessage($message->id);
    $run = fn () => ($this->in)(fn () => app()->call([$job, 'handle']));

    expect(fn () => $run())->toThrow(GraphException::class);
    expect($message->fresh()->status)->toBe(MessageStatus::Queued)
        ->and($job->tries)->toBeGreaterThan(1)->and($job->backoff)->not->toBe([]);

    $run();
    $run(); // a late retry changes nothing
    expect($message->fresh()->wamid)->toBe('wamid.OK')
        ->and(($this->in)(fn () => UsageRecord::query()->where('meter', 'whatsapp.service')->count()))->toBe(1);
    Http::assertSentCount(2);
});

it('retries emails when the email provider is down', function (): void {
    $queued = new SendQueuedMailable(new OnboardingMail($this->hotel->id, 'welcome'));

    expect($queued->tries)->toBeGreaterThan(1)
        ->and($queued->backoff())->not->toBeEmpty()
        ->and($queued->timeout)->toBeGreaterThan(0);
});

it('tells the owner clearly when a payment provider is down, and counts nothing', function (): void {
    BillingEntity::query()->create(['name' => 'CNH', 'legal_name' => 'CNH', 'country_code' => 'CI', 'address' => 'Abidjan', 'email' => 'f@cnh.example', 'invoice_prefix' => 'CNH', 'is_default' => true]);
    subscribe($this->hotel, billingPlan('pro'));
    $this->artisan('billing:invoice');
    $invoice = Invoice::query()->sole();
    $gateway = PaymentGateway::query()->create(['driver' => 'paystack', 'name' => 'Paystack', 'mode' => 'test', 'credentials' => ['secret_key' => 'sk_test'], 'currencies' => ['XOF'], 'active' => true]);
    $owner = staffMember($this->hotel, SystemRole::Owner);

    Http::fake(['api.paystack.co/*' => fn () => throw new ConnectionException('Connection timed out')]);
    asStaffOf($this->hotel, $owner)->post(route('hotel.billing.invoices.pay', $invoice), ['gateway_id' => $gateway->id])
        ->assertSessionHasErrors(['payment' => __('The payment service is not answering. Please try again in a few minutes.')]);

    expect(($this->in)(fn () => PaymentCheckout::query()->sole()->status))->toBe('failed')
        ->and($invoice->fresh()->paid_minor)->toBe(0);
});

it('leaves a payment pending while the provider cannot confirm it, then counts it once', function (): void {
    BillingEntity::query()->create(['name' => 'CNH', 'legal_name' => 'CNH', 'country_code' => 'CI', 'address' => 'Abidjan', 'email' => 'f@cnh.example', 'invoice_prefix' => 'CNH', 'is_default' => true]);
    subscribe($this->hotel, billingPlan('pro', [], null, 100, 50, 10000));
    $this->artisan('billing:invoice');
    $invoice = Invoice::query()->sole();
    $gateway = PaymentGateway::query()->create(['driver' => 'paystack', 'name' => 'Paystack', 'mode' => 'test', 'credentials' => ['secret_key' => 'sk_test'], 'currencies' => ['XOF'], 'active' => true]);
    $checkout = ($this->in)(fn () => PaymentCheckout::query()->create(['payment_gateway_id' => $gateway->id, 'kind' => 'invoice', 'invoice_id' => $invoice->id, 'amount_minor' => $invoice->total_minor, 'currency' => 'XOF', 'reference' => 'HRS-TEST', 'status' => 'pending']));

    Http::fakeSequence('api.paystack.co/*')
        ->push(['status' => false, 'message' => 'Internal error'], 500)
        ->push(['status' => true, 'data' => ['status' => 'success', 'amount' => $invoice->total_minor * 100, 'currency' => 'XOF', 'id' => 7]], 200)
        ->push(['status' => true, 'data' => ['status' => 'success', 'amount' => $invoice->total_minor * 100, 'currency' => 'XOF', 'id' => 7]], 200);

    $this->get(route('payments.return', $checkout));
    expect(($this->in)(fn () => $checkout->fresh()->status))->toBe('pending');

    $this->get(route('payments.return', $checkout));
    $this->get(route('payments.return', $checkout));
    expect(($this->in)(fn () => $checkout->fresh()->status))->toBe('paid')
        ->and($invoice->fresh()->payments()->count())->toBe(1);
});
