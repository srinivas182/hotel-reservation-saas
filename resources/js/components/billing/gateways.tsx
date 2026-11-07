import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import PaymentGatewayController from '@/actions/App/Domains/Billing/Http/Controllers/PaymentGatewayController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
const fieldLabel = (f: string) =>
    ({
        secret_key: t('Secret key'),
        webhook_hash: t('Webhook secret hash'),
        apikey: t('API key'),
        site_id: t('Site ID'),
        master_key: t('Master key'),
        private_key: t('Private key'),
        token: t('Token'),
    })[f] ?? f;

function GatewayForm({
    gateway,
    drivers,
    onDone,
}: {
    gateway?: Gateway;
    drivers: Driver[];
    onDone?: () => void;
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

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({
                    ...d,
                    driver: gateway ? undefined : d.driver,
                    currencies: d.currencies
                        .split(',')
                        .map((c) => c.trim().toUpperCase())
                        .filter((c) => c.length === 3),
                }));
                const options = {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.setData('credentials', {});
                        onDone?.();
                    },
                };
                if (gateway) {
                    form.put(
                        PaymentGatewayController.update.url(gateway.id),
                        options,
                    );
                } else {
                    form.post(PaymentGatewayController.store.url(), options);
                }
            }}
            className="grid gap-2 rounded-xl border p-3 text-sm sm:grid-cols-3"
        >
            <label className="grid gap-0.5 text-xs">
                {t('Provider')}
                <select
                    className={selectClass}
                    value={form.data.driver}
                    disabled={!!gateway}
                    onChange={(e) =>
                        form.setData((d) => ({
                            ...d,
                            driver: e.target.value,
                            name:
                                drivers.find((x) => x.value === e.target.value)
                                    ?.label ?? d.name,
                        }))
                    }
                >
                    {drivers.map((d) => (
                        <option key={d.value} value={d.value}>
                            {d.label}
                        </option>
                    ))}
                </select>
            </label>
            <label className="grid gap-0.5 text-xs">
                {t('Name shown to hotels')}
                <Input
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
            </label>
            <label className="grid gap-0.5 text-xs">
                {t('Mode')}
                <select
                    className={selectClass}
                    value={form.data.mode}
                    onChange={(e) => form.setData('mode', e.target.value)}
                >
                    <option value="test">{t('Test')}</option>
                    <option value="live">{t('Live')}</option>
                </select>
            </label>
            {driver?.fields.map((f) => (
                <label key={f} className="grid gap-0.5 text-xs">
                    {fieldLabel(f)}
                    <Input
                        type="password"
                        autoComplete="off"
                        placeholder={
                            gateway?.configured[f]
                                ? t('Saved — leave empty to keep')
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
                </label>
            ))}
            <label className="grid gap-0.5 text-xs">
                {t('Currencies (comma separated)')}
                <Input
                    value={form.data.currencies}
                    onChange={(e) => form.setData('currencies', e.target.value)}
                />
                <InputError message={errors.currencies} />
            </label>
            <label className="flex items-center gap-1 text-xs">
                <input
                    type="checkbox"
                    checked={form.data.active}
                    onChange={(e) => form.setData('active', e.target.checked)}
                />
                {t('Offered to hotels')}
            </label>
            {driver && (
                <p className="text-xs text-muted-foreground sm:col-span-3">
                    {t(
                        'Notification (webhook) URL to set in the provider dashboard: :url',
                        { url: driver.webhook },
                    )}
                </p>
            )}
            <div>
                <Button size="sm" disabled={form.processing}>
                    {gateway ? t('Save') : t('Add provider')}
                </Button>
            </div>
        </form>
    );
}

export function Gateways({
    gateways,
    drivers,
}: {
    gateways: Gateway[];
    drivers: Driver[];
}) {
    const [adding, setAdding] = useState(false);

    return (
        <section className="flex flex-col gap-2 text-sm">
            <div className="flex items-center justify-between">
                <Heading
                    variant="small"
                    title={t('Online payment providers')}
                    description={t(
                        'Hotels can pay invoices and buy credits with any active provider in their currency.',
                    )}
                />
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setAdding(!adding)}
                >
                    {adding ? t('Close') : t('Add provider')}
                </Button>
            </div>
            {adding && (
                <GatewayForm
                    drivers={drivers}
                    onDone={() => setAdding(false)}
                />
            )}
            {gateways.map((g) => (
                <details key={g.id} className="rounded-xl border p-2">
                    <summary className="flex cursor-pointer flex-wrap items-center gap-2">
                        {g.name} ·{' '}
                        {drivers.find((d) => d.value === g.driver)?.label} ·{' '}
                        {g.currencies.join(', ')}
                        <Badge
                            variant={g.mode === 'live' ? 'default' : 'outline'}
                        >
                            {g.mode === 'live' ? t('Live') : t('Test')}
                        </Badge>
                        {g.active ? (
                            <Badge variant="secondary">{t('Active')}</Badge>
                        ) : (
                            <Badge variant="outline">{t('Off')}</Badge>
                        )}
                    </summary>
                    <GatewayForm gateway={g} drivers={drivers} />
                </details>
            ))}
            {gateways.length === 0 && !adding && (
                <p className="text-muted-foreground">
                    {t(
                        'No provider yet: hotels pay by transfer or mobile money, recorded by you.',
                    )}
                </p>
            )}
        </section>
    );
}
