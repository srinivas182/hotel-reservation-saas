<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Billing\Payments\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Platform: the online payment providers hotels can pay with. Keys are
 * write-only (an empty field keeps the stored value).
 */
final class PaymentGatewayController extends Controller
{
    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->save($request, $user, null);
    }

    public function update(Request $request, PaymentGateway $gateway, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->save($request, $user, $gateway);
    }

    private function save(Request $request, User $user, ?PaymentGateway $gateway): RedirectResponse
    {
        $data = $request->validate([
            'driver' => [$gateway === null ? 'required' : 'prohibited', Rule::in(array_keys(PaymentGateway::DRIVERS))],
            'name' => ['required', 'string', 'max:80'],
            'mode' => ['required', Rule::in(['test', 'live'])],
            'credentials' => ['array'],
            'credentials.*' => ['nullable', 'string', 'max:500'],
            'currencies' => ['required', 'array', 'min:1'],
            'currencies.*' => ['string', 'size:3'],
            'active' => ['required', 'boolean'],
        ]);

        $driver = $gateway->driver ?? (string) $data['driver'];
        $fields = PaymentGateway::DRIVERS[$driver]['fields'];
        $credentials = $gateway->credentials ?? [];

        foreach ($fields as $field) {
            $value = trim((string) ($data['credentials'][$field] ?? ''));
            if ($value !== '') {
                $credentials[$field] = $value;
            }
        }

        $values = [
            'name' => $data['name'], 'mode' => $data['mode'], 'credentials' => $credentials,
            'currencies' => array_values(array_unique(array_map('strtoupper', (array) $data['currencies']))), 'active' => (bool) $data['active'],
        ];

        $gateway === null ? $gateway = PaymentGateway::query()->create([...$values, 'driver' => $driver]) : $gateway->update($values);
        Audit::record('billing.gateway_saved', 'Payment provider saved', causer: $user, properties: ['gateway' => $gateway->name, 'driver' => $driver, 'mode' => $gateway->mode, 'active' => $gateway->active]);

        return back();
    }
}
