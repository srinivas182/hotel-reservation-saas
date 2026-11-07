<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Exceptions\PlanChangeNotPossible;
use App\Domains\Billing\Invoices\Invoicing;
use App\Domains\Billing\Models\CreditPack;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Payments\Checkouts;
use App\Domains\Billing\Payments\PaymentFailed;
use App\Domains\Billing\Payments\PaymentGateway;
use App\Domains\Billing\Subscriptions;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The owner pays invoices and buys credit packs online, and changes plan.
 */
final class HotelPaymentController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Checkouts $checkouts,
    ) {}

    public function payInvoice(Request $request, string $invoice, #[CurrentUser] User $user): Response
    {
        $found = Invoice::query()->where('hotel_id', $this->context->id())->findOrFail($invoice);

        return $this->go(fn () => $this->checkouts->forInvoice($found, $this->gateway($request), $user));
    }

    public function buyPack(Request $request, CreditPack $pack, #[CurrentUser] User $user): Response
    {
        return $this->go(fn () => $this->checkouts->forPack($pack, $this->gateway($request), $user));
    }

    public function changePlan(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'string', Rule::exists('plans', 'id')->where('active', true)]]);
        $subscription = Subscription::query()->firstOrFail();

        try {
            app(Subscriptions::class)->changePlan($subscription, Plan::query()->findOrFail((string) $data['plan_id']), $user);
        } catch (PlanChangeNotPossible $e) {
            throw ValidationException::withMessages(['plan_id' => $e->getMessage()]);
        }

        // An upgrade is invoiced at once, so it can be paid right away.
        app(Invoicing::class)->issueDue($user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Plan updated.')]);

        return back();
    }

    private function gateway(Request $request): PaymentGateway
    {
        $data = $request->validate(['gateway_id' => ['required', 'integer']]);

        return PaymentGateway::query()->where('active', true)->findOrFail((int) $data['gateway_id']);
    }

    /**
     * @param  callable(): \App\Domains\Billing\Payments\PaymentCheckout  $start
     */
    private function go(callable $start): Response
    {
        try {
            $checkout = $start();
        } catch (PaymentFailed $e) {
            throw ValidationException::withMessages(['payment' => $e->getMessage()]);
        }

        // The provider's hosted page (outside the app).
        return Inertia::location((string) $checkout->checkout_url);
    }
}
