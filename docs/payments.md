# Online payments (S13.3)

Hotels pay their invoices and buy credit packs online with any **active** provider
that supports their currency. The platform sets providers up in
**Platform → Billing → Online payment providers**. Keys are stored encrypted and
never shown again (leave a key field empty to keep the stored value).

| Provider    | Keys                                                                         | Notification (webhook) URL                            | Test mode                            |
| ----------- | ---------------------------------------------------------------------------- | ----------------------------------------------------- | ------------------------------------ |
| Paystack    | Secret key (`sk_test_…` / `sk_live_…`)                                       | `https://<app>/webhooks/payments/paystack`            | test or live keys                    |
| Flutterwave | Secret key, and the **secret hash** set in Flutterwave → Settings → Webhooks | `https://<app>/webhooks/payments/flutterwave`         | test or live keys                    |
| CinetPay    | API key, site ID, secret key                                                 | sent automatically with each payment (`notify_url`)   | test account keys                    |
| PayDunya    | Master key, private key, token                                               | sent automatically with each payment (`callback_url`) | **Mode = Test** uses the sandbox API |

## How a payment is counted

1. The owner chooses a provider on Hotel → Billing and is sent to the provider's page.
2. When the payer comes back (`/payments/return/…`) or the provider posts a notification,
   the platform **asks the provider directly** (server to server) whether the payment
   succeeded. Notifications are only a signal: Paystack's signature, Flutterwave's secret
   hash, CinetPay's site ID and PayDunya's master-key hash are checked, but the money
   is counted only after the provider confirms it.
3. The confirmed **amount and currency must match** exactly; otherwise the payment is
   refused and audited.
4. Each payment is applied **once**, however many notifications arrive.
5. A credit pack's credits are added only after payment; the pack gets its own invoice,
   marked paid.

## Before going live

- Run one payment per provider in test mode on staging, end to end.
- CNH confirms each provider's fees and payout account in its merchant contract.
- Switch the provider to **Live** with live keys, then keep **Offered to hotels** on.
