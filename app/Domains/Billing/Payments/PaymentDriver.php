<?php

declare(strict_types=1);

namespace App\Domains\Billing\Payments;

use Illuminate\Http\Request;

/**
 * What every online payment provider does: start a hosted checkout,
 * confirm a payment server-to-server, and read its notifications.
 * Notifications are never trusted on their own: they only say which
 * payment to check, and the check asks the provider directly.
 */
interface PaymentDriver
{
    /**
     * @param  array{email: string, name: string, description: string, return_url: string, notify_url: string}  $customer
     * @return array{url: string, token: string|null}
     */
    public function start(PaymentCheckout $checkout, array $customer): array;

    public function verify(PaymentCheckout $checkout): VerifiedPayment;

    /**
     * Our reference (or the provider's token) from a genuine notification;
     * null when the notification cannot be authenticated or is irrelevant.
     *
     * @return array{reference?: string, token?: string}|null
     */
    public function notification(Request $request): ?array;
}
