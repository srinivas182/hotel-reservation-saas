<?php

declare(strict_types=1);

namespace App\Domains\Conversations;

enum MessageSource: string
{
    case Guest = 'guest';
    case Staff = 'staff';
    case System = 'system';
    case BusinessApp = 'business_app';
    case Assistant = 'assistant';
}
