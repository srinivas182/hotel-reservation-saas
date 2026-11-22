import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import PaymentGatewayController from '@/actions/App/Domains/Billing/Http/Controllers/PaymentGatewayController';
import {
    DataTable,
    Field,
    FieldGroup,
    Section,
    SidePanel,
    Switch,
} from '@/components/page';
import PasswordInput from '@/components/password-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

export type Gateway = {
    id: number;
    driver: string;
    name: string;
    mode: string;
    currencies: string[];
    active: boolean;
    configured: Record<string, boolean>;
};
export type Driver = {
    value: string;
    label: string;
    fields: string[];
    webhook: string;
};

const fieldLabel = (f: string) =>
    ({
        secret_key: t('Secret key'),
        webhook_hash: t('Webhook secret hash'),
        webhook_secret: t('Webhook signing secret (whsec_…)'),
        apikey: t('API key'),
        site_id: t('Site ID'),
        master_key: t('Master key'),
        private_key: t('Private key'),
        token: t('Token'),
    })[f] ?? f;

function GatewayEditor({
    gateway,
    drivers,
    onClose,
}: {
    gateway: Gateway | null;
    drivers: Driver[];
    onClose: () => void;
}) {
    const form = useForm({
        driver: gateway?.driver ?? drivers[0]?.value ?? 'paystack',
        name: gateway?.name ?? drivers[0]?.label ?? '',
        mode: gateway?.mode ?? 'test',
        credentials: {} as Record<string, string>,
        currencies: (gateway?.currencies ?? ['XOF']).join(', '),
        active: gateway?.active ?? false,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const driver = drivers.find((d) => d.value === form.data.driver);

    const save = () => {
        form.transform((d) => ({
            ...d,
            driver: gateway ? undefined : d.driver,
            currencies: d.currencies
                .split(',')
                .map((c) => c.trim().toUpperCase())
                .filter((c) => c.length === 3),
        }));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (gateway) {
            form.put(PaymentGatewayController.update.url(gateway.id), options);
        } else {
            form.post(PaymentGatewayController.store.url(), options);
        }
    };

    return (
        <SidePanel
            open
            onClose={onClose}
            title={gateway ? gateway.name : t('New payment provider')}
            description={t(
                'Hotels pay invoices and buy credits with active providers in their currency.',
            )}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button disabled={form.processing} onClick={save}>
                        {gateway ? t('Save') : t('Add provider')}
                    </Button>
                </>
            }
        >
            <div className="grid gap-6">
                <FieldGroup title={t('Provider')}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('Provider')}>
                            <select
                                className="h-9 w-full"
                                value={form.data.driver}
                                disabled={!!gateway}
                                onChange={(e) =>
                                    form.setData((d) => ({
                                        ...d,
                                        driver: e.target.value,
                                        name:
                                            drivers.find(
                                                (x) =>
                                                    x.value === e.target.value,
                                            )?.label ?? d.name,
                                    }))
                                }
                            >
                                {drivers.map((d) => (
                                    <option key={d.value} value={d.value}>
                                        {d.label}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label={t('Name shown to hotels')}>
                            <Input
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label={t('Mode')}
                            hint={t('Test: no real money moves.')}
                        >
                            <select
                                className="h-9 w-full"
                                value={form.data.mode}
                                onChange={(e) =>
                                    form.setData('mode', e.target.value)
                                }
                            >
                                <option value="test">{t('Test')}</option>
                                <option value="live">{t('Live')}</option>
                            </select>
                        </Field>
                        <Field
                            label={t('Currencies (comma separated)')}
                            error={errors.currencies}
                        >
                            <Input
                                value={form.data.currencies}
                                onChange={(e) =>
                                    form.setData('currencies', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                </FieldGroup>
                {driver && driver.fields.length > 0 && (
                    <FieldGroup
                        title={t('Keys')}
                        description={t(
                            'Stored encrypted and never shown again. Leave a saved key empty to keep it.',
                        )}
                    >
                        <div className="grid gap-4">
                            {driver.fields.map((f) => (
                                <Field
                                    key={f}
                                    label={fieldLabel(f)}
                                    error={errors[`credentials.${f}`]}
                                >
                                    <PasswordInput
                                        autoComplete="off"
                                        placeholder={
                                            gateway?.configured[f]
                                                ? t(
                                                      'Saved — leave empty to keep',
                                                  )
                                                : ''
                                        }
                                        value={form.data.credentials[f] ?? ''}
                                        onChange={(e) =>
                                            form.setData('credentials', {
                                                ...form.data.credentials,
                                                [f]: e.target.value,
                                            })
                                        }
                                    />
                                </Field>
                            ))}
                        </div>
                    </FieldGroup>
                )}
                {driver && (
                    <FieldGroup title={t('Notifications (webhook)')}>
                        <p className="text-xs text-muted-foreground">
                            {t('Address to set in the provider dashboard:')}
                        </p>
                        <code className="rounded-md border bg-muted/40 px-3 py-2 text-xs break-all">
                            {driver.webhook}
                        </code>
                        {driver.value === 'stripe' && (
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'Stripe: Developers → Webhooks → Add endpoint with this URL and the events checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed and checkout.session.expired; then copy its signing secret here. Use an sk_test_ key in Test mode.',
                                )}
                            </p>
                        )}
                    </FieldGroup>
                )}
                <FieldGroup title={t('Availability')}>
                    <Switch
                        label={t('Offered to hotels')}
                        checked={form.data.active}
                        onChange={(v) => form.setData('active', v)}
                    />
                </FieldGroup>
            </div>
        </SidePanel>
    );
}

export function GatewaysTab({
    gateways,
    drivers,
}: {
    gateways: Gateway[];
    drivers: Driver[];
}) {
    const [editing, setEditing] = useState<Gateway | 'new' | null>(null);

    return (
        <>
            <Section
                flush
                title={t('Payment providers')}
                description={t(
                    'Hotels can pay invoices and buy credits with any active provider in their currency.',
                )}
                actions={
                    <Button size="sm" onClick={() => setEditing('new')}>
                        {t('Add provider')}
                    </Button>
                }
            >
                <DataTable
                    rows={gateways}
                    rowKey={(g) => g.id}
                    empty={t(
                        'No provider yet: hotels pay by transfer or mobile money, recorded by you.',
                    )}
                    onRowClick={(g) => setEditing(g)}
                    columns={[
                        {
                            key: 'name',
                            header: t('Provider'),
                            cell: (g) => (
                                <div>
                                    <div className="font-medium">{g.name}</div>
                                    <div className="text-xs text-muted-foreground">
                                        {
                                            drivers.find(
                                                (d) => d.value === g.driver,
                                            )?.label
                                        }
                                    </div>
                                </div>
                            ),
                        },
                        {
                            key: 'currencies',
                            header: t('Currencies'),
                            cell: (g) => (
                                <span className="font-mono text-xs">
                                    {g.currencies.join(', ')}
                                </span>
                            ),
                        },
                        {
                            key: 'mode',
                            header: t('Mode'),
                            cell: (g) => (
                                <Badge
                                    variant={
                                        g.mode === 'live'
                                            ? 'default'
                                            : 'outline'
                                    }
                                >
                                    {g.mode === 'live' ? t('Live') : t('Test')}
                                </Badge>
                            ),
                        },
                        {
                            key: 'keys',
                            header: t('Keys'),
                            cell: (g) =>
                                Object.values(g.configured).every(Boolean) ? (
                                    <span className="text-xs text-muted-foreground">
                                        {t('Saved')}
                                    </span>
                                ) : (
                                    <Badge variant="destructive">
                                        {t('Missing')}
                                    </Badge>
                                ),
                        },
                        {
                            key: 'status',
                            header: t('Status'),
                            cell: (g) =>
                                g.active ? (
                                    <Badge>{t('Active')}</Badge>
                                ) : (
                                    <Badge variant="outline">{t('Off')}</Badge>
                                ),
                        },
                    ]}
                />
            </Section>
            {editing !== null && (
                <GatewayEditor
                    key={editing === 'new' ? 'new' : editing.id}
                    gateway={editing === 'new' ? null : editing}
                    drivers={drivers}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}
