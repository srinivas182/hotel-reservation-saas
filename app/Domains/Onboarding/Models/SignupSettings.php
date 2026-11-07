<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $mode open, code, closed
 * @property bool $approval_required
 * @property bool $block_disposable_emails
 * @property bool $emails_enabled
 * @property list<string>|null $notify_emails
 */
class SignupSettings extends Model
{
    protected $table = 'signup_settings';

    protected $guarded = ['id'];

    protected $attributes = ['mode' => 'code', 'approval_required' => true, 'block_disposable_emails' => true, 'emails_enabled' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['approval_required' => 'boolean', 'block_disposable_emails' => 'boolean', 'emails_enabled' => 'boolean', 'notify_emails' => 'array'];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([]);
    }
}
