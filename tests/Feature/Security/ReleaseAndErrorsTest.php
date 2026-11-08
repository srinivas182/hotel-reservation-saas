<?php

declare(strict_types=1);

use App\Support\Logging\SentryScrubber;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\UserDataBag;

it('shows branded error pages in the visitor’s language, with the request reference', function (): void {
    $response = $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')->get('/cette-page-n-existe-pas')->assertNotFound();

    $response->assertSee('Page introuvable')->assertSee(config('app.name'))
        ->assertSee((string) $response->headers->get('X-Request-Id'));
});

it('uses the same layout for every error page', function (int $code): void {
    expect(view()->exists("errors.{$code}"))->toBeTrue()
        ->and((string) view("errors.{$code}")->render())->toContain('<main role="main">');
})->with([403, 404, 419, 429, 500, 503]);

it('knows its version and reports it', function (): void {
    expect(config('app.version'))->toBe(trim((string) file_get_contents(base_path('VERSION'))))
        ->and(config('app.version'))->toMatch('/^\d+\.\d+\.\d+(-[a-z0-9.]+)?$/')
        ->and(config('sentry.release'))->toBe(config('app.version'))
        ->and(config('sentry.send_default_pii'))->toBeFalse();
});

it('strips personal data from error reports', function (): void {
    $event = Event::createEvent();
    $event->setMessage('Send to +225 07 08 09 10 11 failed for awa@example.com');
    $event->setRequest(['url' => 'https://app.example/hotel/inbox', 'data' => ['body' => 'secret message'], 'cookies' => ['laravel_session' => 'x'], 'headers' => ['Cookie' => 'x', 'Authorization' => 'Bearer y', 'Accept' => 'text/html']]);
    $event->setUser(UserDataBag::createFromArray(['id' => 'u1', 'email' => 'owner@hotel.ci', 'ip_address' => '1.2.3.4']));
    $event->setExceptions([new ExceptionDataBag(new RuntimeException('Token EAAGm0PX4ZCpsBAJZBabcdefghijklmnop rejected for +2250708091011'))]);

    $clean = SentryScrubber::beforeSend($event);

    expect($clean->getMessage())->toBe('Send to [phone] failed for [email]')
        ->and($clean->getRequest())->not->toHaveKey('data')->not->toHaveKey('cookies')
        ->and(array_keys($clean->getRequest()['headers']))->toBe(['accept'])
        ->and($clean->getUser()?->getEmail())->toBeNull()
        ->and($clean->getUser()?->getIpAddress())->toBeNull()
        ->and($clean->getExceptions()[0]->getValue())->toBe('Token [secret] rejected for [phone]');
});
