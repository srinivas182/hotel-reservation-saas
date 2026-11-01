<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Email;

/**
 * Supported email providers. All are connected over authenticated SMTP,
 * which every one of them offers, so no provider-specific SDK is needed.
 * API transports (for bounce and complaint webhooks) can be added later.
 */
enum MailDriver: string
{
    case Brevo = 'brevo';
    case Ses = 'ses';
    case Postmark = 'postmark';
    case Mailgun = 'mailgun';
    case Resend = 'resend';
    case Smtp = 'smtp';

    public function label(): string
    {
        return match ($this) {
            self::Brevo => 'Brevo',
            self::Ses => 'Amazon SES',
            self::Postmark => 'Postmark',
            self::Mailgun => 'Mailgun',
            self::Resend => 'Resend',
            self::Smtp => __('Other SMTP server'),
        };
    }

    /**
     * Credential fields shown for this provider. Secret fields are never
     * sent back to the browser once saved.
     *
     * @return list<array{key: string, label: string, secret: bool, required: bool}>
     */
    public function fields(): array
    {
        $username = ['key' => 'username', 'label' => __('SMTP username'), 'secret' => false, 'required' => true];
        $password = ['key' => 'password', 'label' => __('SMTP password or API key'), 'secret' => true, 'required' => true];

        return match ($this) {
            self::Ses => [
                ['key' => 'region', 'label' => __('AWS region (for example eu-west-1)'), 'secret' => false, 'required' => true],
                $username,
                $password,
            ],
            self::Mailgun => [
                ['key' => 'region', 'label' => __('Region (us or eu)'), 'secret' => false, 'required' => true],
                $username,
                $password,
            ],
            self::Postmark => [
                ['key' => 'password', 'label' => __('Server API token'), 'secret' => true, 'required' => true],
            ],
            self::Resend => [
                ['key' => 'password', 'label' => __('API key'), 'secret' => true, 'required' => true],
            ],
            self::Smtp => [
                ['key' => 'host', 'label' => __('Host'), 'secret' => false, 'required' => true],
                ['key' => 'port', 'label' => __('Port'), 'secret' => false, 'required' => true],
                $username,
                $password,
            ],
            self::Brevo => [$username, $password],
        };
    }

    /**
     * SMTP connection for the saved credentials.
     *
     * @param  array<string, string>  $credentials
     * @return array{host: string, port: int, username: string, password: string}
     */
    public function connection(array $credentials): array
    {
        $username = $credentials['username'] ?? '';
        $password = $credentials['password'] ?? '';

        return match ($this) {
            self::Brevo => ['host' => 'smtp-relay.brevo.com', 'port' => 587, 'username' => $username, 'password' => $password],
            self::Ses => ['host' => 'email-smtp.'.($credentials['region'] ?? 'us-east-1').'.amazonaws.com', 'port' => 587, 'username' => $username, 'password' => $password],
            self::Postmark => ['host' => 'smtp.postmarkapp.com', 'port' => 587, 'username' => $password, 'password' => $password],
            self::Mailgun => [
                'host' => ($credentials['region'] ?? 'us') === 'eu' ? 'smtp.eu.mailgun.org' : 'smtp.mailgun.org',
                'port' => 587, 'username' => $username, 'password' => $password,
            ],
            self::Resend => ['host' => 'smtp.resend.com', 'port' => 587, 'username' => 'resend', 'password' => $password],
            self::Smtp => [
                'host' => $credentials['host'] ?? '',
                'port' => (int) ($credentials['port'] ?? 587),
                'username' => $username,
                'password' => $password,
            ],
        };
    }
}
