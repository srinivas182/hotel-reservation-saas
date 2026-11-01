<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Email;

use App\Domains\Messaging\Email\Models\MailProvider;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds the transport for a provider. Bound in the container so tests
 * can swap in fake transports.
 */
class MailTransportFactory
{
    public function make(MailProvider $provider): TransportInterface
    {
        $connection = $provider->driver->connection($provider->credentials);

        $transport = new EsmtpTransport(
            $connection['host'],
            $connection['port'],
            $connection['port'] === 465,
        );
        $transport->setUsername($connection['username']);
        $transport->setPassword($connection['password']);

        return $transport;
    }
}
