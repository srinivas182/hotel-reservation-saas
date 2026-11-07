<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Payments\Checkouts;
use App\Domains\Billing\Payments\PaymentCheckout;
use App\Domains\Billing\Payments\PaymentGateway;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Throwable;

/**
 * Where providers send the payer back, and where they post notifications.
 * Both only trigger a server-to-server check with the provider.
 */
final class PaymentReturnController extends Controller
{
    public function back(string $checkout, TenantContext $context): RedirectResponse
    {
        $found = $context->bypass('Payment return', fn () => PaymentCheckout::query()->find($checkout));
        abort_if($found === null, 404);
        $hotel = Hotel::query()->findOrFail($found->hotel_id);

        $settled = $context->run($hotel, fn () => $this->settleQuietly($found));

        Inertia::flash('toast', match ($settled->status) {
            'paid' => ['type' => 'success', 'message' => __('Payment received. Thank you!')],
            'failed' => ['type' => 'error', 'message' => __('The payment did not go through.')],
            default => ['type' => 'info', 'message' => __('Payment in progress: it will show here once the provider confirms it.')],
        });

        return to_route('hotel.billing');
    }

    public function notify(Request $request, string $driver, TenantContext $context): Response
    {
        abort_unless(array_key_exists($driver, PaymentGateway::DRIVERS), 404);
        $authenticated = false;

        foreach (PaymentGateway::query()->where('driver', $driver)->get() as $gateway) {
            $found = $gateway->driver()->notification($request);

            if ($found === null) {
                continue;
            }

            $authenticated = true;
            $checkout = $context->bypass('Payment notification', fn () => PaymentCheckout::query()
                ->where('payment_gateway_id', $gateway->id)
                ->where(fn ($q) => isset($found['reference']) ? $q->where('reference', $found['reference']) : $q->where('provider_token', $found['token'] ?? ''))
                ->first());

            if ($checkout !== null) {
                $context->run(Hotel::query()->findOrFail($checkout->hotel_id), fn () => $this->settleQuietly($checkout));
            }
        }

        return response($authenticated ? 'ok' : 'unauthorised', $authenticated ? 200 : 401);
    }

    private function settleQuietly(PaymentCheckout $checkout): PaymentCheckout
    {
        try {
            return app(Checkouts::class)->settle($checkout);
        } catch (Throwable $e) {
            report($e);

            return $checkout;
        }
    }
}
