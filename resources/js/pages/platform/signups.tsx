import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PlatformSignupController from '@/actions/App/Domains/Onboarding/Http/Controllers/PlatformSignupController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Settings = {
    mode: string;
    approval_required: boolean;
    block_disposable_emails: boolean;
    emails_enabled: boolean;
    notify_emails: string[] | null;
};
type Code = {
    id: number;
    code: string;
    note: string | null;
    uses: number;
    max_uses: number | null;
    expires_at: string | null;
    usable: boolean;
    revoked: boolean;
};
type Signup = {
    id: number;
    hotel: string;
    hotel_id: string | null;
    country: string;
    city: string;
    rooms: number;
    owner: string | null;
    email: string | null;
    verified: boolean;
    status: string | null;
    trial_ends: string | null;
    signed_up: string | null;
};
type Template = {
    id: number;
    country_code: string;
    name: string;
    name_fr: string;
    name_en: string;
    currency: string;
    timezone: string;
    locale: string;
    check_in: string;
    check_out: string;
    taxes: { name: string; rate: number; inclusive: boolean }[];
    categories: string;
    active: boolean;
};
type Legal = {
    kind: string;
    label: string;
    version: number | null;
    fr: string;
    en: string;
    url: string;
};
type Props = {
    settings: Settings;
    codes: Code[];
    signups: Signup[];
    templates: Template[];
    legal: Legal[];
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

function SettingsForm({ settings }: { settings: Settings }) {
    const form = useForm({
        ...settings,
        notify_emails: (settings.notify_emails ?? []).join(', '),
    });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({
                    ...d,
                    notify_emails: d.notify_emails
                        .split(',')
                        .map((x) => x.trim())
                        .filter(Boolean),
                }));
                form.put(PlatformSignupController.settings.url(), {
                    preserveScroll: true,
                });
            }}
            className="grid gap-2 rounded-xl border p-3 text-sm sm:grid-cols-2"
        >
            <label className="grid gap-0.5 text-xs">
                {t('Who can sign up')}
                <select
                    className={selectClass}
                    value={form.data.mode}
                    onChange={(e) => form.setData('mode', e.target.value)}
                >
                    <option value="open">{t('Anyone')}</option>
                    <option value="code">{t('Invitation code only')}</option>
                    <option value="closed">{t('Closed')}</option>
                </select>
            </label>
            <label className="grid gap-0.5 text-xs">
                {t('Notify these emails of sign-ups and go-live requests')}
                <Input
                    value={form.data.notify_emails}
                    onChange={(e) =>
                        form.setData('notify_emails', e.target.value)
                    }
                    placeholder="ops@cnh.example, sales@cnh.example"
                />
                <InputError
                    message={
                        (form.errors as Record<string, string | undefined>)[
                            'notify_emails.0'
                        ]
                    }
                />
            </label>
            {(
                [
                    'approval_required',
                    'block_disposable_emails',
                    'emails_enabled',
                ] as const
            ).map((key) => (
                <label key={key} className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        checked={form.data[key]}
                        onChange={(e) => form.setData(key, e.target.checked)}
                    />
                    {
                        {
                            approval_required: t(
                                'Going live needs platform approval',
                            ),
                            block_disposable_emails: t(
                                'Block throwaway email addresses',
                            ),
                            emails_enabled: t(
                                'Send onboarding emails to hotels',
                            ),
                        }[key]
                    }
                </label>
            ))}
            <div>
                <Button size="sm" disabled={form.processing}>
                    {t('Save')}
                </Button>
            </div>
        </form>
    );
}

function Codes({ codes }: { codes: Code[] }) {
    const form = useForm({ code: '', note: '', max_uses: '', expires_at: '' });

    return (
        <section className="flex flex-col gap-2 text-sm">
            <Heading variant="small" title={t('Invitation codes')} />
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((d) => ({
                        ...d,
                        code: d.code || null,
                        max_uses: d.max_uses === '' ? null : Number(d.max_uses),
                        expires_at: d.expires_at || null,
                    }));
                    form.post(PlatformSignupController.storeCode.url(), {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
                className="flex flex-wrap items-end gap-2"
            >
                <Input
                    aria-label={t('Code')}
                    placeholder={t('Code (empty: random)')}
                    className="w-40"
                    value={form.data.code}
                    onChange={(e) => form.setData('code', e.target.value)}
                />
                <Input
                    aria-label={t('Note')}
                    placeholder={t('Note (e.g. CNH sales, Abidjan)')}
                    className="w-56"
                    value={form.data.note}
                    onChange={(e) => form.setData('note', e.target.value)}
                />
                <Input
                    aria-label={t('Uses')}
                    placeholder={t('Max uses')}
                    type="number"
                    min={1}
                    className="w-28"
                    value={form.data.max_uses}
                    onChange={(e) => form.setData('max_uses', e.target.value)}
                />
                <Input
                    aria-label={t('Expires on')}
                    type="date"
                    className="w-40"
                    value={form.data.expires_at}
                    onChange={(e) => form.setData('expires_at', e.target.value)}
                />
                <Button size="sm" disabled={form.processing}>
                    {t('Create code')}
                </Button>
            </form>
            <ul className="divide-y rounded-xl border">
                {codes.map((c) => (
                    <li
                        key={c.id}
                        className="flex flex-wrap items-center gap-3 px-3 py-1.5"
                    >
                        <span className="font-mono">{c.code}</span>
                        <span className="text-muted-foreground">{c.note}</span>
                        <span>
                            {t(':uses used', { uses: c.uses })}
                            {c.max_uses ? ` / ${c.max_uses}` : ''}
                        </span>
                        {c.expires_at && (
                            <span className="text-muted-foreground">
                                {t('until :date', { date: c.expires_at })}
                            </span>
                        )}
                        <Badge variant={c.usable ? 'secondary' : 'outline'}>
                            {c.usable
                                ? t('Active')
                                : c.revoked
                                  ? t('Revoked')
                                  : t('Used up or expired')}
                        </Badge>
                        {!c.revoked && (
                            <Button
                                size="sm"
                                variant="ghost"
                                className="ml-auto"
                                onClick={() =>
                                    router.post(
                                        PlatformSignupController.revokeCode.url(
                                            c.id,
                                        ),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {t('Revoke')}
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}

function TemplateForm({
    template,
    onDone,
}: {
    template?: Template;
    onDone?: () => void;
}) {
    const form = useForm({
        country_code: template?.country_code ?? '',
        name_fr: template?.name_fr ?? '',
        name_en: template?.name_en ?? '',
        currency: template?.currency ?? 'XOF',
        timezone: template?.timezone ?? 'Africa/Abidjan',
        locale: template?.locale ?? 'fr',
        check_in: template?.check_in ?? '14:00',
        check_out: template?.check_out ?? '12:00',
        taxes: template?.taxes ?? [{ name: 'TVA', rate: 18, inclusive: true }],
        categories: template?.categories ?? 'Standard, Supérieure, Suite',
        active: template?.active ?? true,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const text = (
        key:
            | 'country_code'
            | 'name_fr'
            | 'name_en'
            | 'currency'
            | 'timezone'
            | 'check_in'
            | 'check_out'
            | 'categories',
        label: string,
        disabled = false,
    ) => (
        <label className="grid gap-0.5 text-xs">
            {label}
            <Input
                disabled={disabled}
                value={form.data[key]}
                onChange={(e) => form.setData(key, e.target.value)}
            />
            <InputError message={errors[key]} />
        </label>
    );

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                const options = {
                    preserveScroll: true,
                    onSuccess: () => onDone?.(),
                };
                if (template) {
                    form.transform(({ country_code: _code, ...rest }) => rest);
                    form.put(
                        PlatformSignupController.updateTemplate.url(
                            template.id,
                        ),
                        options,
                    );
                } else {
                    form.post(
                        PlatformSignupController.storeTemplate.url(),
                        options,
                    );
                }
            }}
            className="grid gap-2 p-2 text-sm sm:grid-cols-4"
        >
            {text('country_code', t('Country code'), !!template)}
            {text('name_fr', t('Name (FR)'))}
            {text('name_en', t('Name (EN)'))}
            {text('currency', t('Currency'))}
            {text('timezone', t('Time zone'))}
            <label className="grid gap-0.5 text-xs">
                {t('Language')}
                <select
                    className={selectClass}
                    value={form.data.locale}
                    onChange={(e) => form.setData('locale', e.target.value)}
                >
                    <option value="fr">Français</option>
                    <option value="en">English</option>
                </select>
            </label>
            {text('check_in', t('Check-in from'))}
            {text('check_out', t('Check-out until'))}
            <div className="flex flex-wrap items-end gap-2 sm:col-span-4">
                {form.data.taxes.map((tax, i) => (
                    <span key={i} className="flex items-end gap-1">
                        <Input
                            aria-label={t('Tax name')}
                            className="w-32"
                            value={tax.name}
                            onChange={(e) =>
                                form.setData(
                                    'taxes',
                                    form.data.taxes.map((x, j) =>
                                        j === i
                                            ? { ...x, name: e.target.value }
                                            : x,
                                    ),
                                )
                            }
                        />
                        <Input
                            aria-label={t('Rate (%)')}
                            type="number"
                            step="any"
                            className="w-20"
                            value={tax.rate}
                            onChange={(e) =>
                                form.setData(
                                    'taxes',
                                    form.data.taxes.map((x, j) =>
                                        j === i
                                            ? {
                                                  ...x,
                                                  rate: Number(e.target.value),
                                              }
                                            : x,
                                    ),
                                )
                            }
                        />
                        <label className="flex items-center gap-1 text-xs">
                            <input
                                type="checkbox"
                                checked={tax.inclusive}
                                onChange={(e) =>
                                    form.setData(
                                        'taxes',
                                        form.data.taxes.map((x, j) =>
                                            j === i
                                                ? {
                                                      ...x,
                                                      inclusive:
                                                          e.target.checked,
                                                  }
                                                : x,
                                        ),
                                    )
                                }
                            />
                            {t('included in prices')}
                        </label>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                form.setData(
                                    'taxes',
                                    form.data.taxes.filter((_, j) => j !== i),
                                )
                            }
                        >
                            ×
                        </Button>
                    </span>
                ))}
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        form.setData('taxes', [
                            ...form.data.taxes,
                            { name: '', rate: 0, inclusive: true },
                        ])
                    }
                >
                    {t('Add a tax')}
                </Button>
            </div>
            <div className="sm:col-span-3">
                {text(
                    'categories',
                    t('Suggested room categories (comma separated)'),
                )}
            </div>
            <label className="flex items-center gap-1 text-xs">
                <input
                    type="checkbox"
                    checked={form.data.active}
                    onChange={(e) => form.setData('active', e.target.checked)}
                />
                {t('Offered at sign-up')}
            </label>
            <div>
                <Button size="sm" disabled={form.processing}>
                    {template ? t('Save') : t('Add country')}
                </Button>
            </div>
        </form>
    );
}

function LegalEditor({ doc }: { doc: Legal }) {
    const form = useForm({ fr: doc.fr, en: doc.en });

    return (
        <details className="rounded-xl border p-2 text-sm">
            <summary className="cursor-pointer">
                {doc.label} ·{' '}
                {t('version :version', { version: doc.version ?? 0 })}{' '}
                <a
                    href={doc.url}
                    target="_blank"
                    rel="noreferrer"
                    className="underline"
                >
                    {t('View')}
                </a>
            </summary>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    if (
                        window.confirm(
                            t(
                                'Publish a new version? Owners will be asked to accept it at their next login.',
                            ),
                        )
                    ) {
                        form.post(
                            PlatformSignupController.publishLegal.url(doc.kind),
                            { preserveScroll: true },
                        );
                    }
                }}
                className="mt-2 grid gap-2 sm:grid-cols-2"
            >
                <textarea
                    aria-label="FR"
                    className="min-h-60 rounded-md border border-input bg-transparent p-2 font-mono text-xs"
                    value={form.data.fr}
                    onChange={(e) => form.setData('fr', e.target.value)}
                />
                <textarea
                    aria-label="EN"
                    className="min-h-60 rounded-md border border-input bg-transparent p-2 font-mono text-xs"
                    value={form.data.en}
                    onChange={(e) => form.setData('en', e.target.value)}
                />
                <div>
                    <Button size="sm" disabled={form.processing}>
                        {t('Publish a new version')}
                    </Button>
                </div>
            </form>
        </details>
    );
}

export default function PlatformSignups({
    settings,
    codes,
    signups,
    templates,
    legal,
}: Props) {
    const [addingCountry, setAddingCountry] = useState(false);

    return (
        <>
            <Head title={t('Sign-ups')} />
            <div className="flex max-w-6xl flex-col gap-6 p-4">
                <Heading
                    title={t('Sign-ups')}
                    description={t(
                        'Hotels that signed up, how they can sign up, and the defaults they start with.',
                    )}
                />
                <SettingsForm settings={settings} />

                <section className="flex flex-col gap-2 text-sm">
                    <Heading variant="small" title={t('New hotels')} />
                    <ul className="divide-y rounded-xl border">
                        {signups.length === 0 && (
                            <li className="px-3 py-2 text-muted-foreground">
                                {t('No sign-ups yet.')}
                            </li>
                        )}
                        {signups.map((s) => (
                            <li
                                key={s.id}
                                className="flex flex-wrap items-center gap-3 px-3 py-2"
                            >
                                <span className="font-medium">{s.hotel}</span>
                                <span className="text-muted-foreground">
                                    {s.city}, {s.country} ·{' '}
                                    {t(':rooms rooms', { rooms: s.rooms })}
                                </span>
                                <span>
                                    {s.owner} · {s.email}
                                </span>
                                {!s.verified && (
                                    <Badge variant="outline">
                                        {t('Email not confirmed')}
                                    </Badge>
                                )}
                                {s.status && (
                                    <Badge
                                        variant={
                                            s.status === 'suspended'
                                                ? 'destructive'
                                                : 'secondary'
                                        }
                                    >
                                        {s.status}
                                    </Badge>
                                )}
                                {s.trial_ends && (
                                    <span className="text-muted-foreground">
                                        {t('Trial until :date', {
                                            date: s.trial_ends,
                                        })}
                                    </span>
                                )}
                                {s.hotel_id && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="ml-auto"
                                        onClick={() => {
                                            const suspend =
                                                s.status !== 'suspended';
                                            const reason = suspend
                                                ? window.prompt(
                                                      t(
                                                          'Reason for suspending',
                                                      ),
                                                  )
                                                : '';
                                            if (!suspend || reason) {
                                                router.post(
                                                    PlatformSignupController.suspend.url(
                                                        s.hotel_id as string,
                                                    ),
                                                    { suspend, reason },
                                                    { preserveScroll: true },
                                                );
                                            }
                                        }}
                                    >
                                        {s.status === 'suspended'
                                            ? t('Reactivate')
                                            : t('Suspend')}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>

                <Codes codes={codes} />

                <section className="flex flex-col gap-2 text-sm">
                    <div className="flex items-center justify-between">
                        <Heading
                            variant="small"
                            title={t('Country templates')}
                            description={t(
                                'Tax rates are starting values: check them with a local accountant.',
                            )}
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setAddingCountry(!addingCountry)}
                        >
                            {addingCountry ? t('Close') : t('Add country')}
                        </Button>
                    </div>
                    {addingCountry && (
                        <div className="rounded-xl border">
                            <TemplateForm
                                onDone={() => setAddingCountry(false)}
                            />
                        </div>
                    )}
                    {templates.map((tpl) => (
                        <details key={tpl.id} className="rounded-xl border p-2">
                            <summary className="cursor-pointer">
                                {tpl.country_code} · {tpl.name} · {tpl.currency}{' '}
                                ·{' '}
                                {tpl.taxes
                                    .map((x) => `${x.name} ${x.rate}%`)
                                    .join(', ')}{' '}
                                {!tpl.active && (
                                    <Badge variant="outline">{t('Off')}</Badge>
                                )}
                            </summary>
                            <TemplateForm template={tpl} />
                        </details>
                    ))}
                </section>

                <section className="flex flex-col gap-2 text-sm">
                    <Heading
                        variant="small"
                        title={t('Legal texts')}
                        description={t(
                            'Markdown. Each change is published as a new version.',
                        )}
                    />
                    {legal.map((doc) => (
                        <LegalEditor key={doc.kind} doc={doc} />
                    ))}
                </section>
            </div>
        </>
    );
}
