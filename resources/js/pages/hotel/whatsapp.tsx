import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import WhatsAppConnectionController from '@/actions/App/Domains/WhatsApp/Http/Controllers/WhatsAppConnectionController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { launchEmbeddedSignup } from '@/lib/embedded-signup';
import { t } from '@/lib/i18n';

type Account = {
    status: string;
    status_label: string;
    display_phone: string | null;
    verified_name: string | null;
    quality_rating: string | null;
    messaging_limit: string | null;
    coexistence: boolean;
    connected_at: string | null;
    last_error: string | null;
};

type Props = {
    account: Account | null;
    signup: {
        app_id: string | null;
        config_id: string | null;
        graph_version: string;
        ready: boolean;
    };
    errors?: Record<string, string>;
};

export default function WhatsAppConnection({ account, signup, errors }: Props) {
    const [coexistence, setCoexistence] = useState(true);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const connected = account !== null && account.status !== 'disconnected';

    const connect = () => {
        setProblem(null);
        setBusy(true);
        launchEmbeddedSignup({
            appId: signup.app_id ?? '',
            configId: signup.config_id ?? '',
            version: signup.graph_version,
            coexistence,
        })
            .then(({ code, wabaId, phoneNumberId }) => {
                router.post(
                    WhatsAppConnectionController.connect.url(),
                    {
                        code,
                        waba_id: wabaId,
                        phone_number_id: phoneNumberId,
                        coexistence,
                    },
                    { preserveScroll: true, onFinish: () => setBusy(false) },
                );
            })
            .catch((error: Error) => {
                setBusy(false);
                setProblem(
                    error.message === 'cancelled'
                        ? t('The connection was cancelled.')
                        : t('The connection did not complete: :reason', {
                              reason: error.message,
                          }),
                );
            });
    };

    return (
        <>
            <Head title="WhatsApp" />
            <div className="flex max-w-3xl flex-col gap-6 p-4">
                <Heading
                    title="WhatsApp"
                    description={t(
                        'Connect the hotel’s WhatsApp number so guests can message you and the assistant can answer.',
                    )}
                />

                {connected && account && (
                    <section className="flex flex-col gap-3 rounded-xl border p-4 text-sm">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-lg font-semibold">
                                {account.display_phone}
                            </span>
                            <Badge
                                variant={
                                    account.status === 'connected'
                                        ? 'default'
                                        : 'destructive'
                                }
                            >
                                {account.status_label}
                            </Badge>
                            {account.coexistence && (
                                <Badge variant="secondary">
                                    {t(
                                        'Also used in the WhatsApp Business app',
                                    )}
                                </Badge>
                            )}
                        </div>
                        <dl className="grid gap-1 sm:grid-cols-2">
                            <div>
                                <dt className="text-muted-foreground">
                                    {t('Name shown to guests')}
                                </dt>
                                <dd>{account.verified_name ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    {t('Quality rating')}
                                </dt>
                                <dd>{account.quality_rating ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    {t('Messaging limit')}
                                </dt>
                                <dd>{account.messaging_limit ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    {t('Connected')}
                                </dt>
                                <dd>{account.connected_at ?? '—'}</dd>
                            </div>
                        </dl>
                        {account.last_error && (
                            <p className="text-destructive">
                                {account.last_error}
                            </p>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        WhatsAppConnectionController.check.url(),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {t('Check connection')}
                            </Button>
                            {account.status === 'reconnect_required' &&
                                signup.ready && (
                                    <Button onClick={connect} disabled={busy}>
                                        {t('Reconnect')}
                                    </Button>
                                )}
                            <Button
                                variant="ghost"
                                className="text-destructive"
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            t(
                                                'Disconnect WhatsApp? Guests will no longer reach the hotel through this platform.',
                                            ),
                                        )
                                    ) {
                                        router.delete(
                                            WhatsAppConnectionController.disconnect.url(),
                                            { preserveScroll: true },
                                        );
                                    }
                                }}
                            >
                                {t('Disconnect')}
                            </Button>
                        </div>
                    </section>
                )}

                {!connected && (
                    <section className="flex flex-col gap-4 rounded-xl border p-4 text-sm">
                        {!signup.ready ? (
                            <p className="text-muted-foreground">
                                {t(
                                    'WhatsApp connection is not available yet. The platform team is completing the Meta setup.',
                                )}
                            </p>
                        ) : (
                            <>
                                <fieldset className="flex flex-col gap-2">
                                    <legend className="mb-1 font-medium">
                                        {t('Which number?')}
                                    </legend>
                                    <label className="flex items-start gap-2">
                                        <input
                                            type="radio"
                                            className="mt-1"
                                            checked={coexistence}
                                            onChange={() =>
                                                setCoexistence(true)
                                            }
                                        />
                                        <span>
                                            {t(
                                                'The number we already use in the WhatsApp Business app',
                                            )}
                                            <span className="block text-muted-foreground">
                                                {t(
                                                    'Reception keeps using the app; chat history is kept. Update the app first.',
                                                )}
                                            </span>
                                        </span>
                                    </label>
                                    <label className="flex items-start gap-2">
                                        <input
                                            type="radio"
                                            className="mt-1"
                                            checked={!coexistence}
                                            onChange={() =>
                                                setCoexistence(false)
                                            }
                                        />
                                        <span>
                                            {t(
                                                'A new number, used only through this platform',
                                            )}
                                            <span className="block text-muted-foreground">
                                                {t(
                                                    'Do not choose this for a number already in a WhatsApp app: its chat history would be lost.',
                                                )}
                                            </span>
                                        </span>
                                    </label>
                                </fieldset>
                                <p className="text-muted-foreground">
                                    {t(
                                        'Meta opens a window where you sign in, choose the hotel’s business and verify the number. Keep the phone with the number nearby.',
                                    )}
                                </p>
                                <Button
                                    className="w-fit"
                                    onClick={connect}
                                    disabled={busy}
                                >
                                    {busy
                                        ? t('Connecting…')
                                        : t('Connect WhatsApp')}
                                </Button>
                                {problem && (
                                    <p className="text-destructive">
                                        {problem}
                                    </p>
                                )}
                                <InputError message={errors?.code} />
                            </>
                        )}
                    </section>
                )}

                <p className="text-xs text-muted-foreground">
                    {t(
                        'The hotel pays Meta directly for WhatsApp messages: add a payment method to the WhatsApp account in Meta Business Suite after connecting.',
                    )}
                </p>
            </div>
        </>
    );
}

WhatsAppConnection.layout = {
    breadcrumbs: [
        { title: 'WhatsApp', href: WhatsAppConnectionController.show() },
    ],
};
