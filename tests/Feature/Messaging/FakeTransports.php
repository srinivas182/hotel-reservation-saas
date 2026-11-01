<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domains\Messaging\Email\MailTransportFactory;
use App\Domains\Messaging\Email\Models\MailProvider;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Test transports keyed by provider name: names starting with "down"
 * fail; others record what they sent.
 */
final class FakeTransports extends MailTransportFactory
{
    /** @var array<string, list<SentMessage>> */
    public array $sent = [];

    public function make(MailProvider $provider): TransportInterface
    {
        $factory = $this;

        return new class($provider->name, $factory) extends AbstractTransport
        {
            public function __construct(private readonly string $name, private readonly FakeTransports $factory)
            {
                parent::__construct();
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                if (str_starts_with($this->name, 'down')) {
                    throw new TransportException("{$this->name} is unreachable");
                }

                return parent::send($message, $envelope);
            }

            protected function doSend(SentMessage $message): void
            {
                $this->factory->sent[$this->name][] = $message;
            }

            public function __toString(): string
            {
                return 'fake://'.$this->name;
            }
        };
    }
}
