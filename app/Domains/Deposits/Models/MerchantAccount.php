<?php

declare(strict_types=1);

namespace App\Domains\Deposits\Models;

use App\Domains\Billing\Payments\Drivers\CinetPay;
use App\Domains\Billing\Payments\PaymentCredentials;
use App\Domains\Billing\Payments\PaymentDriver;
use App\Domains\Deposits\Drivers\SimulatedDeposits;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Support\Casts\RecoverableEncryptedArray;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The hotel's own payment account (Sprint F). Guests pay the hotel
 * directly: the platform never holds their money. Keys are stored
 * encrypted and never sent to the browser.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $driver cinetpay, simulation
 * @property string $mode test, live
 * @property array<string, string> $credentials
 * @property bool $active
 * @property CarbonImmutable|null $checked_at
 * @property string|null $last_error
 */
class MerchantAccount extends Model implements PaymentCredentials
{
    use BelongsToHotel;

    /** Each driver and the keys it needs. The simulation is only offered in simulation mode. */
    public const DRIVERS = [
        'cinetpay' => ['label' => 'CinetPay (Wave, Orange Money, MTN MoMo, Moov Money, cartes)', 'fields' => ['apikey', 'site_id', 'secret_key']],
        'simulation' => ['label' => 'Simulation', 'fields' => []],
    ];

    protected $guarded = ['id', 'hotel_id'];

    protected $hidden = ['credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['credentials' => RecoverableEncryptedArray::class, 'active' => 'boolean', 'checked_at' => 'immutable_datetime'];
    }

    public static function current(): ?self
    {
        return self::query()->first();
    }

    /** Ready to take payments. */
    public function usable(): bool
    {
        return $this->active && ($this->driver !== 'simulation' || (bool) config('simulation.enabled'));
    }

    public function driver(): PaymentDriver
    {
        return match ($this->driver) {
            'cinetpay' => new CinetPay($this),
            'simulation' => new SimulatedDeposits,
            default => throw new \InvalidArgumentException("Unknown deposit driver {$this->driver}."),
        };
    }

    public function credential(string $key): string
    {
        return (string) ($this->credentials[$key] ?? '');
    }

    public function paymentMode(): string
    {
        return $this->mode;
    }

    /**
     * The keys with all but the last four characters hidden, for the settings page.
     *
     * @return array<string, string>
     */
    public function maskedCredentials(): array
    {
        $masked = [];
        foreach (self::DRIVERS[$this->driver]['fields'] ?? [] as $field) {
            $value = $this->credential($field);
            $masked[$field] = $value === '' ? '' : str_repeat('•', 6).mb_substr($value, -4);
        }

        return $masked;
    }
}
