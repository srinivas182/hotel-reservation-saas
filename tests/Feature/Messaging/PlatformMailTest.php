<?php

declare(strict_types=1);

use App\Domains\Messaging\Email\EmailAllowance;
use App\Domains\Messaging\Email\EmailCategory;
use App\Domains\Messaging\Email\MailTransportFactory;
use App\Domains\Messaging\Email\Models\EmailMessage;
use App\Domains\Messaging\Email\Models\MailProvider;
use App\Domains\Messaging\Usage\UsageRecord;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Messaging\FakeTransports;

require_once __DIR__.'/../Access/helpers.php';
require_once __DIR__.'/FakeTransports.php';

function categorizedMail(EmailCategory $category, string $subject = 'Your booking'): Mailable
{
    return new class($category, $subject) extends Mailable
    {
        public function __construct(private readonly EmailCategory $category, private readonly string $mailSubject) {}

        public function envelope(): Envelope
        {
            return new Envelope(subject: $this->mailSubject);
        }

        public function content(): Content
        {
            return new Content(htmlString: '<p>Hello</p>');
        }

        public function headers(): Headers
        {
            return new Headers(text: [EmailCategory::HEADER => $this->category->value]);
        }
    };
}

function provider(string $name, int $priority, bool $enabled = true): MailProvider
{
    return MailProvider::query()->create([
        'name' => $name,
        'driver' => 'smtp',
        'credentials' => ['host' => 'smtp.test', 'port' => '587', 'username' => 'u', 'password' => 'secret-'.$name],
        'from_address' => "{$name}@platform.test",
        'enabled' => $enabled,
        'priority' => $priority,
    ]);
}

beforeEach(function (): void {
    $this->fakes = new FakeTransports;
    app()->instance(MailTransportFactory::class, $this->fakes);
    Mail::forgetMailers();
    $this->hotel = Hotel::factory()->create(['name' => 'First Class Lodge']);
});

it('fails over to the next provider and logs which one sent', function (): void {
    provider('down-primary', 1);
    $backup = provider('backup', 2);

    app(TenantContext::class)->run($this->hotel, fn () => Mail::to('guest@example.com')->send(categorizedMail(EmailCategory::Guest)));

    $log = EmailMessage::query()->sole();

    expect($this->fakes->sent)->toHaveKey('backup')
        ->and($log->mail_provider_id)->toBe($backup->id)
        ->and($log->status)->toBe('sent')
        ->and($log->hotel_id)->toBe($this->hotel->id)
        ->and($log->recipients)->toBe(['guest@example.com']);
});

it('sends from the provider address and keeps the display name', function (): void {
    provider('primary', 1);

    $mail = categorizedMail(EmailCategory::Guest)->from('ignored@hotel.test', 'First Class Lodge');
    app(TenantContext::class)->run($this->hotel, fn () => Mail::to('guest@example.com')->send($mail));

    $sent = $this->fakes->sent['primary'][0]->getOriginalMessage();

    expect($sent->getFrom()[0]->getAddress())->toBe('primary@platform.test')
        ->and($sent->getFrom()[0]->getName())->toBe('First Class Lodge')
        ->and($sent->getHeaders()->has(EmailCategory::HEADER))->toBeFalse();
});

it('logs a failure when every provider is down', function (): void {
    provider('down-one', 1);
    provider('down-two', 2);

    expect(fn () => app(TenantContext::class)->run($this->hotel, fn () => Mail::to('guest@example.com')->send(categorizedMail(EmailCategory::Guest))))
        ->toThrow(Symfony\Component\Mailer\Exception\TransportException::class);

    $log = EmailMessage::query()->sole();
    expect($log->status)->toBe('failed')
        ->and($log->error)->toContain('down-two')
        ->and(UsageRecord::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('records usage once per sent countable email, never for security emails', function (): void {
    provider('primary', 1);

    app(TenantContext::class)->run($this->hotel, function (): void {
        Mail::to('guest@example.com')->send(categorizedMail(EmailCategory::Guest));
        Mail::to(['a@example.com', 'b@example.com'])->send(categorizedMail(EmailCategory::Marketing));
        Mail::to('staff@example.com')->send(categorizedMail(EmailCategory::Security, 'Your reset code 123456'));
    });

    $usage = app(TenantContext::class)->run($this->hotel, fn () => UsageRecord::query()->get());
    $security = EmailMessage::query()->where('category', 'security')->sole();

    expect($usage->pluck('quantity', 'meter')->all())->toBe(['email.guest' => 1, 'email.marketing' => 2])
        ->and($security->subject)->toBeNull();
});

it('blocks countable emails over the allowance but always sends security emails', function (): void {
    provider('primary', 1);
    app()->instance(EmailAllowance::class, new class implements EmailAllowance
    {
        public function allows(Hotel $hotel, EmailCategory $category): bool
        {
            return false;
        }
    });
    Mail::forgetMailers();

    app(TenantContext::class)->run($this->hotel, function (): void {
        Mail::to('guest@example.com')->send(categorizedMail(EmailCategory::Guest));
        Mail::to('staff@example.com')->send(categorizedMail(EmailCategory::Security));
    });

    expect(EmailMessage::query()->where('category', 'guest')->sole()->status)->toBe('blocked')
        ->and(EmailMessage::query()->where('category', 'security')->sole()->status)->toBe('sent')
        ->and($this->fakes->sent['primary'])->toHaveCount(1);
});

it('treats emails without a category as uncounted system emails', function (): void {
    provider('primary', 1);

    app(TenantContext::class)->run($this->hotel, fn () => Mail::raw('Hi', fn ($m) => $m->to('x@example.com')->subject('Plain')));

    expect(EmailMessage::query()->sole()->category)->toBe(EmailCategory::System)
        ->and(app(TenantContext::class)->run($this->hotel, fn () => UsageRecord::query()->count()))->toBe(0);
});

it('uses the fallback mailer when no provider is enabled', function (): void {
    provider('disabled', 1, enabled: false);

    app(TenantContext::class)->run($this->hotel, fn () => Mail::to('guest@example.com')->send(categorizedMail(EmailCategory::Guest)));

    $log = EmailMessage::query()->sole();
    expect($log->status)->toBe('sent')
        ->and($log->mail_provider_id)->toBeNull()
        ->and($this->fakes->sent)->toBe([]);
});

it('prunes email logs after 90 days but keeps usage records', function (): void {
    provider('primary', 1);
    app(TenantContext::class)->run($this->hotel, fn () => Mail::to('guest@example.com')->send(categorizedMail(EmailCategory::Guest)));

    $this->travel(91)->days();
    $this->artisan('model:prune', ['--model' => [EmailMessage::class]])->assertSuccessful();

    expect(EmailMessage::query()->count())->toBe(0)
        ->and(app(TenantContext::class)->run($this->hotel, fn () => UsageRecord::query()->count()))->toBe(1);
});

it('keeps usage records append-only', function (): void {
    $record = app(TenantContext::class)->run($this->hotel, fn () => UsageRecord::query()->create([
        'meter' => 'email.guest', 'quantity' => 1, 'source_type' => 'test', 'source_id' => '1', 'occurred_at' => now(),
    ]));

    $record->delete();
})->throws(LogicException::class);
