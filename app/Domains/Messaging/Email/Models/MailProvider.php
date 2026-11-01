<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Email\Models;

use App\Domains\Messaging\Email\MailDriver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A platform-level email provider configured by a super admin.
 * Credentials are stored encrypted and never leave the server.
 *
 * @property int $id
 * @property string $name
 * @property MailDriver $driver
 * @property array<string, string> $credentials
 * @property string $from_address
 * @property bool $enabled
 * @property int $priority
 * @property CarbonInterface|null $last_tested_at
 * @property bool|null $last_test_ok
 * @property string|null $last_test_error
 */
class MailProvider extends Model
{
    protected $fillable = ['name', 'driver', 'credentials', 'from_address', 'enabled', 'priority'];

    protected $hidden = ['credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => MailDriver::class,
            'credentials' => 'encrypted:array',
            'enabled' => 'boolean',
            'priority' => 'integer',
            'last_tested_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }

    /**
     * Enabled providers in the order they are tried.
     *
     * @return Builder<self>
     */
    public static function sendingOrder(): Builder
    {
        return self::query()->where('enabled', true)->orderBy('priority')->orderBy('id');
    }
}
