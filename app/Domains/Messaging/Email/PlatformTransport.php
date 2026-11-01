<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Email;

use App\Domains\Messaging\Email\Models\EmailMessage;
use App\Domains\Messaging\Email\Models\MailProvider;
use App\Domains\Messaging\Usage\UsageLedger;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * The "platform" mail transport.
 *
 * - Reads the email category from the X-Email-Category header (removed
 *   before sending); emails without one are "system" emails.
 * - Countable categories are checked against the hotel's allowance;
 *   blocked emails are logged and not sent. Security and system emails are
 *   never blocked.
 * - Tries each enabled provider in order, sending from that provider's
 *   verified address while keeping the display name (for example the hotel
 *   name). If a provider fails, the next one is tried.
 * - With no provider configured, uses the fallback mailer from config
 *   (Mailpit locally, the array mailer in tests).
 * - Logs every attempt and records usage once per sent countable email.
 */
final class PlatformTransport implements TransportInterface
{
    public function __construct(
        private readonly MailTransportFactory $factory,
        private readonly EmailAllowance $allowance,
        private readonly UsageLedger $ledger,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if (! $message instanceof Email) {
            return $this->fallback()->send($message, $envelope);
        }

        $category = $this->takeCategory($message);
        $context = app(TenantContext::class);
        $hotel = $context->has() ? $context->hotel() : null;

        if ($hotel !== null && $category->countable() && ! $this->allowance->allows($hotel, $category)) {
            $this->log($message, $category, $hotel, EmailMessage::STATUS_BLOCKED, error: 'Email allowance reached.');

            return null;
        }

        $providers = MailProvider::sendingOrder()->get();

        if ($providers->isEmpty()) {
            return $this->deliver($message, $category, $hotel, null, fn (): ?SentMessage => $this->fallback()->send($message, Envelope::create($message)));
        }

        $lastError = null;

        foreach ($providers as $provider) {
            $attempt = clone $message;
            $this->sendAs($attempt, $provider);

            try {
                $sent = $this->factory->make($provider)->send($attempt, Envelope::create($attempt));
            } catch (TransportExceptionInterface $exception) {
                $lastError = $exception;

                continue;
            }

            $this->recordSent($message, $category, $hotel, $provider, $sent);

            return $sent;
        }

        $this->log($message, $category, $hotel, EmailMessage::STATUS_FAILED, error: Str::limit((string) $lastError?->getMessage(), 490));

        throw $lastError ?? new TransportException('No email provider could send the message.');
    }

    public function __toString(): string
    {
        return 'platform';
    }

    /**
     * @param  callable(): ?SentMessage  $send
     */
    private function deliver(Email $message, EmailCategory $category, ?Hotel $hotel, ?MailProvider $provider, callable $send): ?SentMessage
    {
        try {
            $sent = $send();
        } catch (TransportExceptionInterface $exception) {
            $this->log($message, $category, $hotel, EmailMessage::STATUS_FAILED, error: Str::limit($exception->getMessage(), 490));

            throw $exception;
        }

        $this->recordSent($message, $category, $hotel, $provider, $sent);

        return $sent;
    }

    private function recordSent(Email $message, EmailCategory $category, ?Hotel $hotel, ?MailProvider $provider, ?SentMessage $sent): void
    {
        $log = $this->log($message, $category, $hotel, EmailMessage::STATUS_SENT, provider: $provider, messageId: $sent?->getMessageId());

        if ($hotel !== null && $category->countable()) {
            $this->ledger->record($category->meter(), max(1, count($log->recipients)), 'email_message', $log->id);
        }
    }

    private function sendAs(Email $message, MailProvider $provider): void
    {
        $from = $message->getFrom();
        $name = $from === [] ? '' : $from[0]->getName();

        $message->from(new Address($provider->from_address, $name !== '' ? $name : (string) config('app.name')));
    }

    private function takeCategory(Email $message): EmailCategory
    {
        $headers = $message->getHeaders();
        $value = $headers->get(EmailCategory::HEADER)?->getBodyAsString();
        $headers->remove(EmailCategory::HEADER);

        return EmailCategory::tryFrom((string) $value) ?? EmailCategory::System;
    }

    private function log(
        Email $message,
        EmailCategory $category,
        ?Hotel $hotel,
        string $status,
        ?MailProvider $provider = null,
        ?string $messageId = null,
        ?string $error = null,
    ): EmailMessage {
        return EmailMessage::query()->create([
            'hotel_id' => $hotel?->getKey(),
            'category' => $category,
            'recipients' => array_map(fn (Address $address): string => $address->getAddress(), [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()]),
            // Security emails carry sensitive context in the subject; it is not stored.
            'subject' => $category === EmailCategory::Security ? null : Str::limit((string) $message->getSubject(), 250),
            'mail_provider_id' => $provider?->getKey(),
            'status' => $status,
            'provider_message_id' => $messageId === null ? null : Str::limit($messageId, 250),
            'error' => $error,
        ]);
    }

    private function fallback(): TransportInterface
    {
        $name = (string) config('mail.platform_fallback', 'log');

        return Mail::mailer($name === 'platform' ? 'log' : $name)->getSymfonyTransport();
    }
}
