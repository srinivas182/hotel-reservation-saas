<?php

declare(strict_types=1);

namespace App\Domains\Billing\Payments;

use App\Domains\Billing\Payments\Drivers\CinetPay;
use App\Domains\Billing\Payments\Drivers\Flutterwave;
use App\Domains\Billing\Payments\Drivers\PayDunya;
use App\Domains\Billing\Payments\Drivers\Paystack;
use Illuminate\Database\Eloquent\Model;

/**
 * An online payment provider configured by the platform. Credentials are
 * stored encrypted and never sent to the browser.
 *
 * @property int $id
 * @property string $driver
 * @property string $name
 * @property string $mode test, live
 * @property array<string, string> $credentials
 * @property list<string> $currencies
 * @property bool $active
 * @property int $sort_order
 */
class PaymentGateway extends Model
{
    /** Each driver and the credentials it needs. */
    public const DRIVERS = [
        'paystack' => ['label' => 'Paystack', 'fields' => ['secret_key']],
        'flutterwave' => ['label' => 'Flutterwave', 'fields' => ['secret_key', 'webhook_hash']],
        'cinetpay' => ['label' => 'CinetPay', 'fields' => ['apikey', 'site_id', 'secret_key']],
        'paydunya' => ['label' => 'PayDunya', 'fields' => ['master_key', 'private_key', 'token']],
    ];

    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'currencies' => 'array', 'active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function driver(): PaymentDriver
    {
        return match ($this->driver) {
            'paystack' => new Paystack($this),
            'flutterwave' => new Flutterwave($this),
            'cinetpay' => new CinetPay($this),
            'paydunya' => new PayDunya($this),
            default => throw new \InvalidArgumentException("Unknown payment driver {$this->driver}."),
        };
    }

    public function credential(string $key): string
    {
        return (string) ($this->credentials[$key] ?? '');
    }

    public function supports(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->currencies, true);
    }
}
