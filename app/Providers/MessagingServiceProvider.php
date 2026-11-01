<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Messaging\Email\EmailAllowance;
use App\Domains\Messaging\Email\MailTransportFactory;
use App\Domains\Messaging\Email\PlatformTransport;
use App\Domains\Messaging\Email\UnlimitedEmailAllowance;
use App\Domains\Messaging\Usage\UsageLedger;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmailAllowance::class, UnlimitedEmailAllowance::class);
        $this->app->bind(MailTransportFactory::class);
        $this->app->bind(\App\Domains\WhatsApp\Graph\GraphClient::class);

        // WhatsApp webhook handlers by change kind (more are added in S4.2 and S4.3).
        $this->app->tag([\App\Domains\WhatsApp\Webhooks\AccountUpdateHandler::class], 'whatsapp.webhook-handlers');
    }

    public function boot(): void
    {
        Mail::extend('platform', fn (): PlatformTransport => new PlatformTransport(
            $this->app->make(MailTransportFactory::class),
            $this->app->make(EmailAllowance::class),
            $this->app->make(UsageLedger::class),
        ));
    }
}
