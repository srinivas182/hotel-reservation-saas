import { Head, router, useForm } from '@inertiajs/react';
import { Plus, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import type { ChangeEvent, FormEvent } from 'react';
import MailProviderController from '@/actions/App/Domains/Platform/Http/Controllers/MailProviderController';
import InputError from '@/components/input-error';
import {
    DataTable,
    FieldGroup,
    PageBody,
    PageHeader,
    Section,
    SidePanel,
    Switch,
} from '@/components/page';
import PasswordInput from '@/components/password-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { askConfirm } from '@/lib/ask';

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

const selectClass = 'h-9 w-full';

/** A key field: secrets get the show/hide button. */
function InputFor({
    id,
    secret,
    value,
    placeholder,
    onChange,
}: {
    id: string;
    secret: boolean;
    value: string;
    placeholder: string;
    onChange: (e: ChangeEvent<HTMLInputElement>) => void;
}) {
    return secret ? (
        <PasswordInput
            id={id}
            autoComplete="off"
            value={value}
            placeholder={placeholder}
            onChange={onChange}
        />
    ) : (
        <Input
            id={id}
            autoComplete="off"
            value={value}
            placeholder={placeholder}
            onChange={onChange}
        />
    );
}

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
        <form onSubmit={submit} className="grid gap-6">
            <FieldGroup title={t('Provider')}>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="provider-name">
                            {t('Label (only you see this)')}
                        </Label>
                        <Input
                            id="provider-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
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
                            onChange={(e) =>
                                form.setData('driver', e.target.value)
                            }
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
                </div>
            </FieldGroup>
            <FieldGroup
                title={t('Keys')}
                description={t(
                    'Stored encrypted and never shown again. Leave a saved key empty to keep it.',
                )}
            >
                <div className="grid gap-4 sm:grid-cols-2">
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
                                <InputFor
                                    id={`credential-${field.key}`}
                                    secret={field.secret}
                                    value={
                                        form.data.credentials[field.key] ?? ''
                                    }
                                    placeholder={
                                        hasSecret
                                            ? t(
                                                  '••• saved – leave blank to keep',
                                              )
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
            </FieldGroup>
            <FieldGroup title={t('Availability')}>
                <Switch
                    checked={form.data.enabled}
                    onChange={(v) => form.setData('enabled', v)}
                    label={t('Enabled')}
                    hint={t('Emails go through enabled providers in order.')}
                />
            </FieldGroup>
            <div className="flex justify-end gap-2 border-t pt-4">
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
                <Button disabled={form.processing}>
                    {provider ? t('Save provider') : t('Add provider')}
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
    const [testing, setTesting] = useState<number | null>(null);
    const current = providers.find((p) => p.id === editing);

    return (
        <>
            <Head title={t('Email providers')} />
            <PageBody>
                <PageHeader
                    title={t('Email providers')}
                    description={t(
                        'Emails go through enabled providers in order. If one fails, the next is used.',
                    )}
                    actions={
                        <Button onClick={() => setEditing('new')}>
                            <Plus /> {t('Add provider')}
                        </Button>
                    }
                />

                {providers.every((p) => !p.enabled) && (
                    <div
                        role="status"
                        className="flex items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100"
                    >
                        <TriangleAlert className="size-4 shrink-0" />
                        {t(
                            'No provider is enabled. Emails use the server mailer (:mailer).',
                            { mailer: fallbackMailer },
                        )}
                    </div>
                )}

                <Section
                    flush
                    title={t('Providers')}
                    description={t(
                        'Then press “Send test” on a provider. The sender must be verified with the provider (for Brevo: Senders, domains and dedicated IPs).',
                    )}
                    actions={
                        <div className="w-full sm:w-72">
                            <Input
                                type="email"
                                aria-label={t('Send test emails to')}
                                value={testTo}
                                onChange={(e) => setTestTo(e.target.value)}
                                placeholder={t('Send test emails to…')}
                            />
                        </div>
                    }
                >
                    <DataTable
                        rows={providers}
                        rowKey={(p) => p.id}
                        empty={t('No provider yet.')}
                        columns={[
                            {
                                key: 'order',
                                header: '#',
                                cell: (p) => (
                                    <span className="text-muted-foreground tabular-nums">
                                        {p.priority}
                                    </span>
                                ),
                            },
                            {
                                key: 'name',
                                header: t('Provider'),
                                cell: (p) => (
                                    <div>
                                        <div className="font-medium">
                                            {p.name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {p.driver_label} · {p.from_address}
                                        </div>
                                        {p.last_test_ok === false &&
                                            p.last_test_error && (
                                                <div className="mt-1 text-xs text-destructive">
                                                    {p.last_test_error}
                                                </div>
                                            )}
                                    </div>
                                ),
                            },
                            {
                                key: 'sent',
                                header: t('Sent (30 days)'),
                                align: 'right',
                                cell: (p) => p.sent_30_days,
                            },
                            {
                                key: 'status',
                                header: t('Status'),
                                cell: (p) => (
                                    <div className="flex flex-wrap gap-1">
                                        <Badge
                                            variant={
                                                p.enabled
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                        >
                                            {p.enabled
                                                ? t('Enabled')
                                                : t('Disabled')}
                                        </Badge>
                                        {p.last_test_ok !== null && (
                                            <Badge
                                                variant={
                                                    p.last_test_ok
                                                        ? 'outline'
                                                        : 'destructive'
                                                }
                                            >
                                                {p.last_test_ok
                                                    ? t('Test passed')
                                                    : t('Test failed')}
                                            </Badge>
                                        )}
                                    </div>
                                ),
                            },
                            {
                                key: 'actions',
                                header: '',
                                align: 'right',
                                cell: (p) => (
                                    <div className="flex justify-end gap-1">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={
                                                testTo === '' ||
                                                testing !== null
                                            }
                                            title={
                                                testTo === ''
                                                    ? t(
                                                          'Enter the address for test emails first.',
                                                      )
                                                    : undefined
                                            }
                                            onClick={() =>
                                                router.post(
                                                    MailProviderController.test.url(
                                                        p.id,
                                                    ),
                                                    { to: testTo },
                                                    {
                                                        preserveScroll: true,
                                                        onStart: () =>
                                                            setTesting(p.id),
                                                        onFinish: () =>
                                                            setTesting(null),
                                                    },
                                                )
                                            }
                                        >
                                            {testing === p.id
                                                ? t('Sending…')
                                                : t('Send test')}
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setEditing(p.id)}
                                        >
                                            {t('Edit')}
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="text-destructive"
                                            onClick={async () => {
                                                if (
                                                    await askConfirm(
                                                        t('Remove :name?', {
                                                            name: p.name,
                                                        }),
                                                    )
                                                ) {
                                                    router.delete(
                                                        MailProviderController.destroy.url(
                                                            p.id,
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
                                ),
                            },
                        ]}
                    />
                </Section>

                <Section
                    flush
                    title={t('All hotels, last 30 days')}
                    description={t('Emails by type, across every hotel.')}
                >
                    <DataTable
                        rows={totals}
                        rowKey={(r) => r.label}
                        empty="—"
                        columns={[
                            {
                                key: 'type',
                                header: t('Type'),
                                cell: (r) => r.label,
                            },
                            {
                                key: 'sent',
                                header: t('Sent'),
                                align: 'right',
                                cell: (r) => r.sent,
                            },
                            {
                                key: 'failed',
                                header: t('Failed'),
                                align: 'right',
                                cell: (r) => (
                                    <span
                                        className={
                                            r.failed > 0
                                                ? 'text-destructive'
                                                : ''
                                        }
                                    >
                                        {r.failed}
                                    </span>
                                ),
                            },
                            {
                                key: 'blocked',
                                header: t('Blocked'),
                                align: 'right',
                                cell: (r) => r.blocked,
                            },
                        ]}
                    />
                </Section>
            </PageBody>

            <SidePanel
                open={editing !== null}
                onClose={() => setEditing(null)}
                title={current ? current.name : t('Add provider')}
            >
                {editing !== null && (
                    <ProviderForm
                        key={editing}
                        drivers={drivers}
                        provider={current}
                        onDone={() => setEditing(null)}
                    />
                )}
            </SidePanel>
        </>
    );
}

MailProviders.layout = {
    breadcrumbs: [
        { title: 'Email providers', href: MailProviderController.index() },
    ],
};
