<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Email\Concerns;

use App\Domains\Messaging\Email\EmailCategory;
use Illuminate\Mail\Mailables\Headers;

/**
 * For mailables: tags the email with its category for the platform
 * transport (logging, usage and allowance checks).
 */
trait HasEmailCategory
{
    abstract protected function emailCategory(): EmailCategory;

    public function headers(): Headers
    {
        return new Headers(text: [EmailCategory::HEADER => $this->emailCategory()->value]);
    }
}
