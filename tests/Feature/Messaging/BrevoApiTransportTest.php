<?php

declare(strict_types=1);

use App\Domains\Messaging\Email\MailTransportFactory;
use App\Domains\Messaging\Email\Models\MailProvider;
use App\Domains\Messaging\Email\Transports\BrevoApiTransport;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

function brevoProvider(string $key = 'xkeysib-test-key'): MailProvider
{
    return MailProvider::query()->create([
        'name' => 'Brevo', 'driver' => 'brevo', 'from_address' => 'no-reply@platform.test',
        'enabled' => true, 'priority' => 1, 'credentials' => ['password' => $key],
    ]);
}

it('sends Brevo emails through the HTTPS API, not SMTP', function (): void {
    Http::fake([BrevoApiTransport::ENDPOINT => Http::response(['messageId' => '<abc@smtp-relay.mailin.fr>'], 201)]);

    $transport = app(MailTransportFactory::class)->make(brevoProvider());
    $email = (new Email)->from(new Address('no-reply@platform.test', 'Assistant Hôtel'))
        ->to(new Address('guest@example.com', 'Awa'))->bcc('copy@example.com')
        ->replyTo('desk@example.com')->subject('Hello')->text('Plain')->html('<p>Rich</p>')
        ->attach('PDF', 'invoice.pdf', 'application/pdf');

    $sent = $transport->send($email, Envelope::create($email));

    expect($transport)->toBeInstanceOf(BrevoApiTransport::class)
        ->and($sent?->getMessageId())->toBe('<abc@smtp-relay.mailin.fr>');

    Http::assertSent(fn (Request $request): bool => $request->url() === BrevoApiTransport::ENDPOINT
        && $request->header('api-key') === ['xkeysib-test-key']
        && $request['sender'] === ['email' => 'no-reply@platform.test', 'name' => 'Assistant Hôtel']
        && $request['to'] === [['email' => 'guest@example.com', 'name' => 'Awa']]
        && $request['bcc'] === [['email' => 'copy@example.com']]
        && $request['replyTo'] === ['email' => 'desk@example.com']
        && $request['htmlContent'] === '<p>Rich</p>'
        && $request['textContent'] === 'Plain'
        && $request['attachment'][0] === ['name' => 'invoice.pdf', 'content' => base64_encode('PDF')]);
});

it('reports what Brevo refused', function (): void {
    Http::fake([BrevoApiTransport::ENDPOINT => Http::response(['code' => 'unauthorized', 'message' => 'Key not found'], 401)]);

    $email = (new Email)->from('no-reply@platform.test')->to('guest@example.com')->subject('Hi')->text('x');

    app(MailTransportFactory::class)->make(brevoProvider())->send($email, Envelope::create($email));
})->throws(TransportException::class, 'Brevo refused the email (HTTP 401): Key not found');

it('marks the provider tested from the admin screen', function (): void {
    Http::fake([BrevoApiTransport::ENDPOINT => Http::response(['messageId' => '<id@brevo>'], 201)]);
    $provider = brevoProvider();

    $this->actingAs(User::factory()->platformAdmin()->withTwoFactor()->create())
        ->post(route('platform.mail-providers.test', $provider), ['to' => 'me@example.com'])
        ->assertRedirect();

    expect($provider->refresh()->last_test_ok)->toBeTrue();
});

it('explains that a Brevo SMTP key is the wrong key', function (): void {
    $this->actingAs(User::factory()->platformAdmin()->withTwoFactor()->create())
        ->post(route('platform.mail-providers.store'), [
            'name' => 'Brevo', 'driver' => 'brevo', 'from_address' => 'no-reply@platform.test',
            'enabled' => true, 'priority' => 1, 'credentials' => ['password' => 'xsmtpsib-abc'],
        ])
        ->assertSessionHasErrors(['credentials.password']);
});
