import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import MailProviderController from '@/actions/App/Domains/Platform/Http/Controllers/MailProviderController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

type Field = { key: string; label: string; secret: boolean; required: boolean };
type Driver = { value: string; label: string; fields: Field[] };
type Saved = Record<
    string,
    { secret: true; present: boolean } | { secret: false; value: string }
>;

type Provider = {
    id: number;
    name: string;
    driver: string;
    driver_label: string;
    from_address: string;
    enabled: boolean;
    priority: number;
    saved: Saved;
    last_tested_at: string | null;
    last_test_ok: boolean | null;
    last_test_error: string | null;
    sent_30_days: number;
};

type Props = {
    providers: Provider[];
    drivers: Driver[];
    totals: { label: string; sent: number; failed: number; blocked: number }[];
    fallbackMailer: string;
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

function ProviderForm({
    drivers,
    provider,
    onDone,
}: {
    drivers: Driver[];
    provider?: Provider;
    onDone: () => void;
}) {
    const initialCredentials: Record<string, string> = {};
    for (const [key, saved] of Object.entries(provider?.saved ?? {})) {
        initialCredentials[key] = saved.secret ? '' : saved.value;
    }

    const form = useForm({
        name: provider?.name ?? '',
        driver: provider?.driver ?? drivers[0]?.value ?? 'smtp',
        from_address: provider?.from_address ?? '',
        enabled: provider?.enabled ?? false,
        priority: provider?.priority ?? 10,
        credentials: initialCredentials,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const driver = drivers.find((d) => d.value === form.data.driver);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onDone() };

        if (provider) {
            form.put(MailProviderController.update.url(provider.id), options);
        } else {
            form.post(MailProviderController.store.url(), options);
        }
    };

    return (
        <form
            onSubmit={submit}
            className="flex flex-col gap-4 rounded-xl border p-4"
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="provider-name">{t('Name')}</Label>
                    <Input
                        id="provider-name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        required
                    />
                    <InputError message={errors.name} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="provider-driver">{t('Provider')}</Label>
                    <select
                        id="provider-driver"
                        className={selectClass}
                        value={form.data.driver}
                        onChange={(e) => form.setData('driver', e.target.value)}
                    >
                        {drivers.map((d) => (
                            <option key={d.value} value={d.value}>
                                {d.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="provider-from">
                        {t('Sending address (verified domain)')}
                    </Label>
                    <Input
                        id="provider-from"
                        type="email"
                        value={form.data.from_address}
                        placeholder="bookings@example.com"
                        onChange={(e) =>
                            form.setData('from_address', e.target.value)
                        }
                        required
                    />
                    <InputError message={errors.from_address} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="provider-priority">
                        {t('Order (1 is tried first)')}
                    </Label>
                    <Input
                        id="provider-priority"
                        type="number"
                        min={1}
                        max={999}
                        value={form.data.priority}
                        onChange={(e) =>
                            form.setData('priority', Number(e.target.value))
                        }
                    />
                    <InputError message={errors.priority} />
                </div>
                {driver?.fields.map((field) => {
                    const saved = provider?.saved[field.key];
                    const hasSecret =
                        saved?.secret === true &&
                        saved.present &&
                        provider?.driver === form.data.driver;

                    return (
                        <div key={field.key} className="grid gap-2">
                            <Label htmlFor={`credential-${field.key}`}>
                                {field.label}
                            </Label>
                            <Input
                                id={`credential-${field.key}`}
                                type={field.secret ? 'password' : 'text'}
                                autoComplete="off"
                                value={form.data.credentials[field.key] ?? ''}
                                placeholder={
                                    hasSecret
                                        ? t('••• saved – leave blank to keep')
                                        : ''
                                }
                                onChange={(e) =>
                                    form.setData('credentials', {
                                        ...form.data.credentials,
                                        [field.key]: e.target.value,
                                    })
                                }
                            />
                            <InputError
                                message={errors[`credentials.${field.key}`]}
                            />
                        </div>
                    );
                })}
            </div>
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={form.data.enabled}
                    onChange={(e) => form.setData('enabled', e.target.checked)}
                />
                {t('Enabled')}
            </label>
            <div className="flex gap-2">
                <Button disabled={form.processing}>
                    {provider ? t('Save provider') : t('Add provider')}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
            </div>
        </form>
    );
}

export default function MailProviders({
    providers,
    drivers,
    totals,
    fallbackMailer,
}: Props) {
    const [editing, setEditing] = useState<number | 'new' | null>(null);
    const [testTo, setTestTo] = useState('');

    return (
        <>
            <Head title={t('Email providers')} />
            <div className="flex max-w-4xl flex-col gap-8 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={t('Email providers')}
                        description={t(
                            'Emails go through enabled providers in order. If one fails, the next is used.',
                        )}
                    />
                    {editing === null && (
                        <Button onClick={() => setEditing('new')}>
                            {t('Add provider')}
                        </Button>
                    )}
                </div>

                {providers.every((p) => !p.enabled) && (
                    <p className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                        {t(
                            'No provider is enabled. Emails use the server mailer (:mailer).',
                            { mailer: fallbackMailer },
                        )}
                    </p>
                )}

                {editing === 'new' && (
                    <ProviderForm
                        drivers={drivers}
                        onDone={() => setEditing(null)}
                    />
                )}

                <div className="grid gap-2 sm:max-w-sm">
                    <Label htmlFor="test-to">{t('Send test emails to')}</Label>
                    <Input
                        id="test-to"
                        type="email"
                        value={testTo}
                        onChange={(e) => setTestTo(e.target.value)}
                    />
                </div>

                <ul className="flex flex-col gap-3">
                    {providers.map((provider) =>
                        editing === provider.id ? (
                            <li key={provider.id}>
                                <ProviderForm
                                    drivers={drivers}
                                    provider={provider}
                                    onDone={() => setEditing(null)}
                                />
                            </li>
                        ) : (
                            <li
                                key={provider.id}
                                className="flex flex-wrap items-center gap-3 rounded-xl border px-4 py-3 text-sm"
                            >
                                <span className="w-6 text-center text-muted-foreground">
                                    {provider.priority}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="font-medium">
                                        {provider.name}{' '}
                                        <span className="text-muted-foreground">
                                            · {provider.driver_label}
                                        </span>
                                    </div>
                                    <div className="text-muted-foreground">
                                        {provider.from_address} ·{' '}
                                        {t(':count sent in 30 days', {
                                            count: provider.sent_30_days,
                                        })}
                                    </div>
                                    {provider.last_test_ok === false &&
                                        provider.last_test_error && (
                                            <div className="text-destructive">
                                                {provider.last_test_error}
                                            </div>
                                        )}
                                </div>
                                <Badge
                                    variant={
                                        provider.enabled
                                            ? 'default'
                                            : 'secondary'
                                    }
                                >
                                    {provider.enabled
                                        ? t('Enabled')
                                        : t('Disabled')}
                                </Badge>
                                {provider.last_test_ok !== null && (
                                    <Badge
                                        variant={
                                            provider.last_test_ok
                                                ? 'outline'
                                                : 'destructive'
                                        }
                                    >
                                        {provider.last_test_ok
                                            ? t('Test passed')
                                            : t('Test failed')}
                                    </Badge>
                                )}
                                <div className="flex gap-1">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={testTo === ''}
                                        onClick={() =>
                                            router.post(
                                                MailProviderController.test.url(
                                                    provider.id,
                                                ),
                                                { to: testTo },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t('Send test')}
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setEditing(provider.id)}
                                    >
                                        {t('Edit')}
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="text-destructive"
                                        onClick={() => {
                                            if (
                                                window.confirm(
                                                    t('Remove :name?', {
                                                        name: provider.name,
                                                    }),
                                                )
                                            ) {
                                                router.delete(
                                                    MailProviderController.destroy.url(
                                                        provider.id,
                                                    ),
                                                    {
                                                        preserveScroll: true,
                                                    },
                                                );
                                            }
                                        }}
                                    >
                                        {t('Remove')}
                                    </Button>
                                </div>
                            </li>
                        ),
                    )}
                </ul>

                <section className="flex flex-col gap-3">
                    <Heading
                        variant="small"
                        title={t('All hotels, last 30 days')}
                    />
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-3 font-medium">
                                        {t('Type')}
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        {t('Sent')}
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        {t('Failed')}
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        {t('Blocked')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {totals.map((row) => (
                                    <tr key={row.label} className="border-t">
                                        <td className="px-4 py-3">
                                            {row.label}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {row.sent}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {row.failed}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {row.blocked}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </>
    );
}

MailProviders.layout = {
    breadcrumbs: [
        { title: 'Email providers', href: MailProviderController.index() },
    ],
};
