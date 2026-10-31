<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;

it('sends baseline security headers', function (): void {
    $response = $this->get('/');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    $csp = (string) $response->headers->get('Content-Security-Policy-Report-Only');

    expect($csp)->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain('report-uri /csp-report');
});

it('enforces the policy when configured', function (): void {
    config(['security.csp.enforce' => true]);

    $this->get('/')->assertHeader('Content-Security-Policy');
});

it('sends HSTS over HTTPS only', function (): void {
    $this->get('https://localhost/')->assertHeader('Strict-Transport-Security');
    $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');
});

it('accepts and logs CSP violation reports', function (): void {
    Log::spy();

    $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode([
        'csp-report' => ['violated-directive' => 'script-src', 'blocked-uri' => 'https://evil.test/x.js'],
    ]))->assertNoContent();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'CSP violation'
        && $context['blocked-uri'] === 'https://evil.test/x.js');
});
