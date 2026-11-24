import { Check, Copy, ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { t } from '@/lib/i18n';

/**
 * Step-by-step setup for a payment provider, next to its key fields
 * (0.35.7). Used by the hotel's own account (Deposits → Account and rules)
 * and by the platform's providers (Platform → Billing). Says which keys to
 * create, which permissions they need and what notification address to
 * give the provider, before anything is saved.
 */

type Guide = {
    dashboard: string;
    steps: (url: string, mode: string) => string[];
    /** The provider is told the address with each payment: nothing to set. */
    automaticNotify?: boolean;
};

const GUIDES: Record<string, Guide> = {
    stripe: {
        dashboard: 'https://dashboard.stripe.com/apikeys',
        steps: (url, mode) => [
            mode === 'test'
                ? t(
                      'Sign in to dashboard.stripe.com and turn on Test mode (top right). Test and live keys and webhooks are separate.',
                  )
                : t(
                      'Sign in to dashboard.stripe.com in live mode (the account must be activated to take real payments).',
                  ),
            t(
                'Developers → API keys → Create restricted key: name it after the platform (for example "Hotel bookings") and set Checkout Sessions to Write and Balance to Read; leave everything else at None. Copy the key (rk_…) into "Secret key". The standard secret key (sk_…) also works.',
            ),
            t(
                'Developers → Webhooks → Add endpoint (or Add destination). Endpoint URL: :url. Events: checkout.session.completed, checkout.session.expired, checkout.session.async_payment_succeeded and checkout.session.async_payment_failed.',
                { url },
            ),
            t(
                'Open the new endpoint → Signing secret → Reveal, and copy it (whsec_…) into "Webhook signing secret".',
            ),
            mode === 'test'
                ? t(
                      'Save, then check the keys. Test with the card 4242 4242 4242 4242, any future date, any CVC.',
                  )
                : t('Save, then check the keys.'),
        ],
    },
    cinetpay: {
        dashboard: 'https://app.cinetpay.com',
        automaticNotify: true,
        steps: () => [
            t(
                'Sign in to your CinetPay merchant account (app.cinetpay.com). The account must be validated by CinetPay to take real payments.',
            ),
            t(
                'Integration menu: copy the API key (apikey), the Site ID and the Secret key into the fields here.',
            ),
            t(
                'Check in CinetPay which payment methods are active on your account (Wave, Orange Money, MTN MoMo, Moov Money, cards).',
            ),
            t(
                'Notification address: nothing to enter. It is sent to CinetPay with each payment, and each notification is checked with CinetPay before a payment counts.',
            ),
        ],
    },
    paystack: {
        dashboard: 'https://dashboard.paystack.com/#/settings/developers',
        steps: (url, mode) => [
            mode === 'test'
                ? t(
                      'Sign in to dashboard.paystack.com in Test mode (switch at the top).',
                  )
                : t('Sign in to dashboard.paystack.com in Live mode.'),
            t(
                'Settings → API Keys & Webhooks: copy the Secret key (sk_test_… or sk_live_…) into "Secret key".',
            ),
            t(
                'On the same page, set the Webhook URL to :url and save. Paystack signs each notification with the secret key.',
                { url },
            ),
        ],
    },
    flutterwave: {
        dashboard: 'https://app.flutterwave.com/dashboard/settings/apis',
        steps: (url) => [
            t(
                'Sign in to the Flutterwave dashboard (test or live mode, to match the mode here).',
            ),
            t('Settings → API keys: copy the Secret key into "Secret key".'),
            t(
                'Settings → Webhooks: URL :url. Choose a long random "Secret hash", save, and type the same value in "Webhook secret hash".',
                { url },
            ),
        ],
    },
    paydunya: {
        dashboard: 'https://paydunya.com',
        automaticNotify: true,
        steps: () => [
            t(
                'Sign in to paydunya.com → Integrate our APIs → create an application (or open yours).',
            ),
            t(
                'Copy the Master key, the Private key and the Token for the mode chosen here (test keys use the PayDunya sandbox).',
            ),
            t(
                'Notification address: nothing to enter. It is sent to PayDunya with each payment.',
            ),
        ],
    },
};

export function ProviderGuide({
    driver,
    mode,
    notifyUrl,
}: {
    driver: string;
    mode: string;
    notifyUrl: string | null;
}) {
    const [copied, setCopied] = useState(false);
    const guide = GUIDES[driver];

    if (!guide) {
        return null;
    }

    const url = notifyUrl ?? '';
    const copy = () => {
        void navigator.clipboard?.writeText(url).then(() => {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        });
    };

    return (
        <div className="grid gap-3 rounded-lg border bg-muted/30 p-4 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-medium">{t('How to set it up')}</p>
                <a
                    href={guide.dashboard}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                >
                    {t('Open the provider dashboard')}
                    <ExternalLink className="size-3.5" aria-hidden />
                </a>
            </div>
            <ol className="grid list-decimal gap-1.5 pl-5 text-muted-foreground">
                {guide.steps(url, mode).map((step, i) => (
                    <li key={i}>{step}</li>
                ))}
            </ol>
            {!guide.automaticNotify && url !== '' && (
                <div className="grid gap-1">
                    <span className="text-xs font-medium">
                        {t('Notification (webhook) address')}
                    </span>
                    <div className="flex items-center gap-2">
                        <code className="flex-1 rounded-md border bg-background px-3 py-2 text-xs break-all">
                            {url}
                        </code>
                        <button
                            type="button"
                            onClick={copy}
                            className="inline-flex shrink-0 items-center gap-1 rounded-md border bg-background px-2.5 py-2 text-xs font-medium hover:bg-muted"
                        >
                            {copied ? (
                                <Check className="size-3.5" aria-hidden />
                            ) : (
                                <Copy className="size-3.5" aria-hidden />
                            )}
                            {copied ? t('Copied') : t('Copy')}
                        </button>
                    </div>
                </div>
            )}
            <p className="text-xs text-muted-foreground">
                {t(
                    'Keys are typed only here: they are stored encrypted and never shown again. Never send them by WhatsApp, email or chat.',
                )}
            </p>
        </div>
    );
}
