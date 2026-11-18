<?php

declare(strict_types=1);

namespace App\Domains\Workspace;

/**
 * The guided tours (Sprint E). The steps live in the front end
 * (resources/js/components/app/tours.ts); the server only keeps who
 * finished which. A feature tour is shown once, to people who joined
 * before the feature came out (newer people get it in the welcome tour).
 */
final class Tours
{
    /** Keep in step with tours.ts. */
    public const KEYS = ['welcome', 'whatsapp-buttons', 'phone-app', 'simple-mode'];
}
