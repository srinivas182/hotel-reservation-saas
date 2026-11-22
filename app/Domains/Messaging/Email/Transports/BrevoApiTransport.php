<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Email\Transports;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends through Brevo's HTTPS API (api.brevo.com, port 443) instead of SMTP.
 *
 * Cloud hosts such as DigitalOcean block outgoing SMTP ports (25, 465, 587)
 * on new servers, so SMTP times out there while HTTPS always works. Uses the
 * Brevo API key ("xkeysib-…") and a sender verified in Brevo.
 */
final class BrevoApiTransport extends AbstractTransport
{
    public const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    public function __construct(private readonly string $apiKey)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'brevo+api://api.brevo.com';
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        if (! $original instanceof Message) {
            throw new TransportException('Brevo can only send complete e-mails, not raw messages.');
        }

        $email = MessageConverter::toEmail($original);
        $envelope = $message->getEnvelope();

        $sender = $envelope->getSender();
        $fromName = ($email->getFrom()[0] ?? null)?->getName() ?? '';
        $payload = [
            'sender' => array_filter(['email' => $sender->getAddress(), 'name' => $fromName]),
            'to' => $this->addresses($email->getTo()),
            'subject' => (string) $email->getSubject(),
        ];

        // Brevo needs at least one recipient in "to": BCC-only messages use the envelope.
        if ($payload['to'] === []) {
            $payload['to'] = $this->addresses($envelope->getRecipients());
        }
        if ($email->getCc() !== []) {
            $payload['cc'] = $this->addresses($email->getCc());
        }
        if ($email->getBcc() !== []) {
            $payload['bcc'] = $this->addresses($email->getBcc());
        }
        if ($email->getReplyTo() !== []) {
            $payload['replyTo'] = $this->addresses($email->getReplyTo())[0];
        }

        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        if (is_string($html) || is_resource($html)) {
            $payload['htmlContent'] = is_string($html) ? $html : (string) stream_get_contents($html);
        }
        if (is_string($text) || is_resource($text)) {
            $payload['textContent'] = is_string($text) ? $text : (string) stream_get_contents($text);
        }
        if (! isset($payload['htmlContent']) && ! isset($payload['textContent'])) {
            $payload['textContent'] = ' ';
        }

        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            $attachments[] = [
                'name' => $attachment->getFilename() ?? 'attachment',
                'content' => base64_encode($attachment->getBody()),
            ];
        }
        if ($attachments !== []) {
            $payload['attachment'] = $attachments;
        }

        try {
            $response = Http::withHeaders(['api-key' => $this->apiKey])
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(20)
                ->post(self::ENDPOINT, $payload);
        } catch (ConnectionException $exception) {
            throw new TransportException('Could not reach Brevo: '.$exception->getMessage(), 0, $exception);
        }

        if (! $response->successful()) {
            $reason = (string) ($response->json('message') ?? $response->body());

            throw new TransportException(sprintf('Brevo refused the email (HTTP %d): %s', $response->status(), $reason));
        }

        $messageId = $response->json('messageId');
        if (is_string($messageId) && $messageId !== '') {
            $message->setMessageId($messageId);
        }
    }

    /**
     * @param  array<Address>  $addresses
     * @return list<array{email: string, name?: string}>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_map(
            fn (Address $address): array => $address->getName() !== ''
                ? ['email' => $address->getAddress(), 'name' => $address->getName()]
                : ['email' => $address->getAddress()],
            $addresses,
        ));
    }
}
