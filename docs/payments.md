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

# Guests paying the hotel (Sprint F, 0.36)

Guests pay the **hotel's own accounts** from their private payment link; the
platform never holds their money. Set up in **Deposits → Account and rules**
(owner or manager).

## Ways to pay — turn on as many as you like

| Method        | Keys                                                                                           | Notification address                                                          |
| ------------- | ---------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| CinetPay      | API key, Site ID, secret key (Integration menu)                                                | sent automatically with each payment                                          |
| Stripe        | Restricted key (Checkout Sessions: Write, Balance: Read) or secret key; webhook signing secret | `https://<app>/webhooks/deposits/hotel/<hotel id>` (shown with a Copy button) |
| Bank transfer | Account holder, account number (RIB) or IBAN; bank, SWIFT, branch and a note are optional      | none: staff approve each transfer                                             |

Each provider card has a step-by-step guide. One notification address serves
every provider of a hotel: each notification is matched to the provider whose
signature it carries, then the payment is always checked with that provider
before it counts.

## Bank transfer

1. The guest chooses **Pay by bank transfer** on the payment page (deposit or
   the whole stay at the pay-now price). The page then shows the hotel's bank
   details, the amount and a reference (`VIR-<booking>-XXXX`) to write on the
   transfer.
2. The transfer must arrive **within 48 hours, at least 2 days before arrival**,
   and for a deposit before the rooms are released. When there is no longer
   time, the option is not offered.
3. The guest uploads the receipt (photo or PDF, 5 MB) on the page, or sends it
   on WhatsApp. Staff with the deposit alert are told.
4. Staff check the **bank account**, then in **Deposits → Payments → Bank
   transfers to check**: **Money received: approve** (recorded like any payment:
   room bill, pay-now discount, confirmation, receipt to the guest) or **Not
   received** with a sentence for the guest. A receipt alone never confirms.
5. A transfer without a receipt after its deadline ends (`deposits:remind`,
   every 5 minutes) and the guest is told; one with a receipt waits for staff.

Bank details are stored encrypted. Receipts are private files, shown only to
the hotel's staff who record payments.
