<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Templates;

enum TemplateStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paused = 'paused';
    case Disabled = 'disabled';

    public static function fromMeta(string $value): ?self
    {
        return match (strtoupper($value)) {
            'APPROVED' => self::Approved,
            'REJECTED' => self::Rejected,
            'PAUSED' => self::Paused,
            'DISABLED' => self::Disabled,
            'PENDING', 'IN_APPEAL', 'PENDING_DELETION' => self::Pending,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Not submitted'),
            self::Pending => __('Waiting for Meta'),
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
            self::Paused => __('Paused by Meta'),
            self::Disabled => __('Disabled by Meta'),
        };
    }
}
