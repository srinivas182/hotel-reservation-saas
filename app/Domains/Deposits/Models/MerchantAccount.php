<?php

declare(strict_types=1);

namespace App\Domains\Deposits\Models;

use App\Domains\Billing\Payments\Drivers\CinetPay;
use App\Domains\Billing\Payments\Drivers\Stripe;
use App\Domains\Billing\Payments\PaymentCredentials;
use App\Domains\Billing\Payments\PaymentDriver;
use App\Domains\Deposits\Drivers\SimulatedDeposits;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Support\Casts\RecoverableEncryptedArray;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * One of the hotel's own payment accounts (Sprint F; one per provider since 0.36). Guests pay the hotel
 * directly: the platform never holds their money. Keys are stored
 * encrypted and never sent to the browser.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $driver cinetpay, stripe, simulation
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
        'stripe' => ['label' => 'Stripe (cartes / cards)', 'fields' => ['secret_key', 'webhook_secret']],
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

    /**
     * 0.36: a hotel may have one account per provider. The first one that
     * takes payments (CinetPay before Stripe), else the first saved.
     */
    public static function current(): ?self
    {
        $all = self::all();

        return self::ordered($all)->first(fn (self $a): bool => $a->usable()) ?? $all->first();
    }

    /**
     * Every account that takes payments now, CinetPay first.
     *
     * @return Collection<int, self>
     */
    public static function usableAll(): Collection
    {
        return self::ordered(self::all())->filter(fn (self $a): bool => $a->usable())->values();
    }

    public static function forDriver(string $driver): ?self
    {
        return self::query()->where('driver', $driver)->first();
    }

    /**
     * @param  Collection<int, self>  $accounts
     * @return Collection<int, self>
     */
    private static function ordered(Collection $accounts): Collection
    {
        $order = array_flip(array_keys(self::DRIVERS));

        return $accounts->sortBy(fn (self $a): int => $order[$a->driver] ?? 99)->values();
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
            'stripe' => new Stripe($this),
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
