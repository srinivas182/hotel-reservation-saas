<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Email;

use App\Domains\Messaging\Email\Models\MailProvider;
use App\Domains\Messaging\Email\Transports\BrevoApiTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds the transport for a provider. Bound in the container so tests
 * can swap in fake transports.
 */
class MailTransportFactory
{
    /** Seconds before an unreachable SMTP server is given up on (a test never hangs). */
    private const SMTP_TIMEOUT = 15;

    public function make(MailProvider $provider): TransportInterface
    {
        if ($provider->driver->usesApi()) {
            return new BrevoApiTransport((string) ($provider->credentials['password'] ?? ''));
        }

        $connection = $provider->driver->connection($provider->credentials);

        $transport = new EsmtpTransport(
            $connection['host'],
            $connection['port'],
            $connection['port'] === 465,
        );
        $transport->setUsername($connection['username']);
        $transport->setPassword($connection['password']);

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(self::SMTP_TIMEOUT);
        }

        return $transport;
    }
}
