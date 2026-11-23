<?php

declare(strict_types=1);

namespace App\Domains\Deposits\Http\Controllers;

use App\Domains\Deposits\Deposits;
use App\Domains\Deposits\Models\DepositAttempt;
use App\Domains\Deposits\Models\MerchantAccount;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Payment notifications from a hotel's provider (Sprint F). The address
 * names the hotel's account; the notification only triggers a check with
 * the provider, never a change by itself.
 */
final class DepositWebhookController extends Controller
{
    public function __invoke(Request $request, int $account, TenantContext $context): Response
    {
        $found = $context->bypass('Deposit notification', fn () => MerchantAccount::query()->find($account));

        if ($found === null) {
            return response('unknown', 404);
        }

        return $this->handle($request, Hotel::query()->findOrFail($found->hotel_id), $found, $context);
    }

    /**
     * The hotel's address (0.35.7): known before the account is first saved,
     * so a hotel can create its Stripe webhook and paste the signing secret
     * in one go. It always reaches the hotel's current account.
     */
    public function forHotel(Request $request, string $hotel, TenantContext $context): Response
    {
        $found = Hotel::query()->find($hotel);
        $account = $found === null ? null : $context->run($found, fn (): ?MerchantAccount => MerchantAccount::current());

        if ($found === null || $account === null) {
            return response('unknown', 404);
        }

        return $this->handle($request, $found, $account, $context);
    }

    private function handle(Request $request, Hotel $hotel, MerchantAccount $found, TenantContext $context): Response
    {
        return $context->run($hotel, function () use ($request, $found): Response {
            $notice = $found->driver()->notification($request);

            if ($notice === null) {
                return response('unauthorised', 401);
            }

            $attempt = DepositAttempt::query()->with(['account', 'link.booking'])
                ->where('merchant_account_id', $found->id)
                ->where(fn ($q) => isset($notice['reference']) ? $q->where('reference', $notice['reference']) : $q->where('provider_token', $notice['token'] ?? ''))
                ->first();

            if ($attempt !== null) {
                try {
                    app(Deposits::class)->settle($attempt);
                } catch (Throwable $e) {
                    report($e);
                }
            }

            return response('ok');
        });
    }
}
