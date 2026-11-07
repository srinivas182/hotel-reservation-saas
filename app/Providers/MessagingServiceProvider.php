<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Messaging\Email\EmailAllowance;
use App\Domains\Messaging\Email\MailTransportFactory;
use App\Domains\Messaging\Email\PlatformTransport;
use App\Domains\Messaging\Usage\UsageLedger;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmailAllowance::class, \App\Domains\Billing\CreditEmailAllowance::class);
        $this->app->bind(MailTransportFactory::class);
        $this->app->bind(\App\Domains\WhatsApp\Graph\GraphClient::class);
        $this->app->bind(\App\Domains\Ai\Gateway\AiGateway::class, \App\Domains\Ai\Gateway\LaravelAiGateway::class);

        // WhatsApp webhook handlers by change kind (more are added in S4.2 and S4.3).
        $this->app->tag([
            \App\Domains\WhatsApp\Webhooks\AccountUpdateHandler::class,
            \App\Domains\Conversations\Webhooks\MessagesHandler::class,
            \App\Domains\WhatsApp\Templates\TemplateStatusHandler::class,
        ], 'whatsapp.webhook-handlers');
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\Event::listen(\App\Domains\BookingRequests\BookingRequestDecided::class, \App\Domains\BookingRequests\Listeners\TellGuest::class);
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Verified::class, \App\Domains\Onboarding\Listeners\CompleteSignup::class);
        \Illuminate\Support\Facades\Event::listen(\App\Domains\Orders\OrderChanged::class, \App\Domains\Folio\Listeners\PostDeliveredOrder::class);
        \Illuminate\Support\Facades\Event::listen(\App\Domains\Orders\OrderChanged::class, \App\Domains\Orders\Listeners\TellGuestAboutOrderListener::class);
        \Illuminate\Support\Facades\Event::listen(\App\Domains\Restaurant\TableBookingChanged::class, \App\Domains\Restaurant\Listeners\TellGuestAboutTableListener::class);
        \Illuminate\Support\Facades\Event::listen(\App\Domains\Conversations\ConversationNeedsStaff::class, \App\Domains\StaffAlerts\Listeners\AlertStaffAboutConversation::class);

        Mail::extend('platform', fn (): PlatformTransport => new PlatformTransport(
            $this->app->make(MailTransportFactory::class),
            $this->app->make(EmailAllowance::class),
            $this->app->make(UsageLedger::class),
        ));
    }
}
