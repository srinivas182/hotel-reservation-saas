import { Head, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import PlatformSignupController from '@/actions/App/Domains/Onboarding/Http/Controllers/PlatformSignupController';
import {
    DataTable,
    Field,
    FieldGroup,
    PageBody,
    PageHeader,
    Section,
    SidePanel,
    StatTile,
    Switch,
    Tabs,
    useTabs,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import { askConfirm, askText } from '@/lib/ask';

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
    setup: { done: number; total: number } | null;
    golive: { requested_at: string | null; decision: string | null } | null;
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
    funnel: { key: string; label: string; count: number }[];
};

/** Who can sign up and what happens next: one settings card. */
function SettingsTab({ settings }: { settings: Settings }) {
    const form = useForm({
        ...settings,
        notify_emails: (settings.notify_emails ?? []).join(', '),
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            className="flex flex-col gap-6"
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
        >
            <Section
                aside
                title={t('Access')}
                description={t(
                    'Who can create a hotel account on the website.',
                )}
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('Who can sign up')}>
                        <select
                            className="h-9"
                            value={form.data.mode}
                            onChange={(e) =>
                                form.setData('mode', e.target.value)
                            }
                        >
                            <option value="open">{t('Anyone')}</option>
                            <option value="code">
                                {t('Invitation code only')}
                            </option>
                            <option value="closed">{t('Closed')}</option>
                        </select>
                    </Field>
                    <div className="grid content-start gap-4 sm:pt-6">
                        <Switch
                            checked={form.data.block_disposable_emails}
                            onChange={(v) =>
                                form.setData('block_disposable_emails', v)
                            }
                            label={t('Block throwaway email addresses')}
                        />
                    </div>
                </div>
            </Section>
            <Section
                aside
                title={t('Going live')}
                description={t(
                    'What happens after a hotel signs up and finishes its setup.',
                )}
            >
                <div className="grid gap-4">
                    <Switch
                        checked={form.data.approval_required}
                        onChange={(v) => form.setData('approval_required', v)}
                        label={t('Going live needs platform approval')}
                    />
                    <Switch
                        checked={form.data.emails_enabled}
                        onChange={(v) => form.setData('emails_enabled', v)}
                        label={t('Send onboarding emails to hotels')}
                    />
                    <Field
                        label={t(
                            'Notify these emails of sign-ups and go-live requests',
                        )}
                        hint={t('Separate addresses with commas.')}
                        error={errors['notify_emails.0']}
                    >
                        <Input
                            value={form.data.notify_emails}
                            onChange={(e) =>
                                form.setData('notify_emails', e.target.value)
                            }
                            placeholder="ops@cnh.example, sales@cnh.example"
                        />
                    </Field>
                </div>
            </Section>
            <div className="flex justify-end">
                <Button disabled={form.processing}>{t('Save settings')}</Button>
            </div>
        </form>
    );
}

function CodeEditor({ open, onClose }: { open: boolean; onClose: () => void }) {
    const form = useForm({ code: '', note: '', max_uses: '', expires_at: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const submit = () => {
        form.transform((d) => ({
            ...d,
            code: d.code || null,
            max_uses: d.max_uses === '' ? null : Number(d.max_uses),
            expires_at: d.expires_at || null,
        }));
        form.post(PlatformSignupController.storeCode.url(), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <SidePanel
            open={open}
            onClose={onClose}
            title={t('New invitation code')}
            description={t(
                'Give it to a hotel so it can sign up while sign-ups need a code.',
            )}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button disabled={form.processing} onClick={submit}>
                        {t('Create code')}
                    </Button>
                </>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label={t('Code')}
                    hint={t('Leave empty for a random code.')}
                    error={errors.code}
                >
                    <Input
                        className="font-mono uppercase"
                        value={form.data.code}
                        onChange={(e) => form.setData('code', e.target.value)}
                    />
                </Field>
                <Field label={t('Note')} error={errors.note}>
                    <Input
                        placeholder={t('e.g. CNH sales, Abidjan')}
                        value={form.data.note}
                        onChange={(e) => form.setData('note', e.target.value)}
                    />
                </Field>
                <Field
                    label={t('Max uses')}
                    hint={t('Empty: no limit')}
                    error={errors.max_uses}
                >
                    <Input
                        type="number"
                        min={1}
                        value={form.data.max_uses}
                        onChange={(e) =>
                            form.setData('max_uses', e.target.value)
                        }
                    />
                </Field>
                <Field label={t('Expires on')} error={errors.expires_at}>
                    <Input
                        type="date"
                        value={form.data.expires_at}
                        onChange={(e) =>
                            form.setData('expires_at', e.target.value)
                        }
                    />
                </Field>
            </div>
        </SidePanel>
    );
}

function TemplateEditor({
    template,
    onClose,
}: {
    template: Template | 'new' | null;
    onClose: () => void;
}) {
    const tpl = template === 'new' || template === null ? undefined : template;

    return (
        <SidePanel
            open={template !== null}
            onClose={onClose}
            title={
                tpl
                    ? t('Edit :country', { country: tpl.name })
                    : t('Add country')
            }
            description={t(
                'Defaults a new hotel in this country starts with. Tax rates are starting values: check them with a local accountant.',
            )}
        >
            {template !== null && (
                <TemplateForm
                    key={tpl?.id ?? 'new'}
                    template={tpl}
                    onDone={onClose}
                />
            )}
        </SidePanel>
    );
}

function TemplateForm({
    template,
    onDone,
}: {
    template?: Template;
    onDone: () => void;
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
    type TextKey =
        | 'country_code'
        | 'name_fr'
        | 'name_en'
        | 'currency'
        | 'timezone'
        | 'check_in'
        | 'check_out'
        | 'categories';
    const text = (key: TextKey, label: string, disabled = false) => (
        <Field label={label} error={errors[key]}>
            <Input
                disabled={disabled}
                value={form.data[key]}
                onChange={(e) => form.setData(key, e.target.value)}
            />
        </Field>
    );
    const setTax = (i: number, patch: Partial<Template['taxes'][number]>) =>
        form.setData(
            'taxes',
            form.data.taxes.map((x, j) => (j === i ? { ...x, ...patch } : x)),
        );

    return (
        <form
            className="grid gap-6"
            onSubmit={(e) => {
                e.preventDefault();
                const options = { preserveScroll: true, onSuccess: onDone };
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
        >
            <FieldGroup title={t('Country')}>
                <div className="grid gap-4 sm:grid-cols-3">
                    {text('country_code', t('Country code'), !!template)}
                    {text('name_fr', t('Name (French)'))}
                    {text('name_en', t('Name (English)'))}
                </div>
            </FieldGroup>
            <FieldGroup title={t('Defaults')}>
                <div className="grid gap-4 sm:grid-cols-3">
                    {text('currency', t('Currency'))}
                    {text('timezone', t('Time zone'))}
                    <Field label={t('Language')}>
                        <select
                            className="h-9"
                            value={form.data.locale}
                            onChange={(e) =>
                                form.setData('locale', e.target.value)
                            }
                        >
                            <option value="fr">Français</option>
                            <option value="en">English</option>
                        </select>
                    </Field>
                    {text('check_in', t('Check-in from'))}
                    {text('check_out', t('Check-out until'))}
                </div>
                {text(
                    'categories',
                    t('Suggested room categories (comma separated)'),
                )}
            </FieldGroup>
            <FieldGroup
                title={t('Taxes')}
                description={t('Applied to room prices by default.')}
            >
                <div className="overflow-hidden rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                                <th className="px-3 py-2 font-medium">
                                    {t('Tax name')}
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    {t('Rate (%)')}
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    {t('Included in prices')}
                                </th>
                                <th />
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {form.data.taxes.map((tax, i) => (
                                <tr key={i}>
                                    <td className="px-3 py-2">
                                        <Input
                                            aria-label={t('Tax name')}
                                            value={tax.name}
                                            onChange={(e) =>
                                                setTax(i, {
                                                    name: e.target.value,
                                                })
                                            }
                                        />
                                    </td>
                                    <td className="w-28 px-3 py-2">
                                        <Input
                                            aria-label={t('Rate (%)')}
                                            type="number"
                                            step="any"
                                            value={tax.rate}
                                            onChange={(e) =>
                                                setTax(i, {
                                                    rate: Number(
                                                        e.target.value,
                                                    ),
                                                })
                                            }
                                        />
                                    </td>
                                    <td className="px-3 py-2">
                                        <Switch
                                            checked={tax.inclusive}
                                            onChange={(v) =>
                                                setTax(i, { inclusive: v })
                                            }
                                            label={
                                                tax.inclusive
                                                    ? t('Yes')
                                                    : t('No')
                                            }
                                        />
                                    </td>
                                    <td className="w-10 px-1 py-2 text-right">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            aria-label={t('Remove')}
                                            onClick={() =>
                                                form.setData(
                                                    'taxes',
                                                    form.data.taxes.filter(
                                                        (_, j) => j !== i,
                                                    ),
                                                )
                                            }
                                        >
                                            ×
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            form.setData('taxes', [
                                ...form.data.taxes,
                                { name: '', rate: 0, inclusive: true },
                            ])
                        }
                    >
                        <Plus /> {t('Add a tax')}
                    </Button>
                </div>
            </FieldGroup>
            <FieldGroup title={t('Availability')}>
                <Switch
                    checked={form.data.active}
                    onChange={(v) => form.setData('active', v)}
                    label={t('Offered at sign-up')}
                />
            </FieldGroup>
            <div className="flex justify-end gap-2 border-t pt-4">
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
                <Button disabled={form.processing}>
                    {template ? t('Save') : t('Add country')}
                </Button>
            </div>
        </form>
    );
}

function LegalEditor({
    doc,
    onClose,
}: {
    doc: Legal | null;
    onClose: () => void;
}) {
    return (
        <SidePanel
            open={doc !== null}
            onClose={onClose}
            title={doc?.label ?? ''}
            description={t(
                'Markdown. Each change is published as a new version.',
            )}
        >
            {doc && <LegalForm key={doc.kind} doc={doc} onDone={onClose} />}
        </SidePanel>
    );
}

function LegalForm({ doc, onDone }: { doc: Legal; onDone: () => void }) {
    const form = useForm({ fr: doc.fr, en: doc.en });

    return (
        <form
            className="grid gap-4"
            onSubmit={async (e) => {
                e.preventDefault();
                if (
                    await askConfirm(
                        t(
                            'Publish a new version? Owners will be asked to accept it at their next login.',
                        ),
                    )
                ) {
                    form.post(
                        PlatformSignupController.publishLegal.url(doc.kind),
                        { preserveScroll: true, onSuccess: onDone },
                    );
                }
            }}
        >
            <Field label={t('French')}>
                <textarea
                    className="min-h-64 font-mono text-xs"
                    value={form.data.fr}
                    onChange={(e) => form.setData('fr', e.target.value)}
                />
            </Field>
            <Field label={t('English')}>
                <textarea
                    className="min-h-64 font-mono text-xs"
                    value={form.data.en}
                    onChange={(e) => form.setData('en', e.target.value)}
                />
            </Field>
            <div className="flex items-center justify-between gap-2 border-t pt-4">
                <a
                    href={doc.url}
                    target="_blank"
                    rel="noreferrer"
                    className="text-sm text-brand underline-offset-4 hover:underline"
                >
                    {t('View the published version')}
                </a>
                <Button disabled={form.processing}>
                    {t('Publish a new version')}
                </Button>
            </div>
        </form>
    );
}

function SignupActions({ s }: { s: Signup }) {
    if (!s.hotel_id) {
        return null;
    }

    const id = s.hotel_id;
    const asks = s.golive?.requested_at && s.golive.decision === null;

    return (
        <div className="flex justify-end gap-1">
            {asks && (
                <>
                    <Button
                        size="sm"
                        onClick={() =>
                            router.post(
                                PlatformSignupController.decideGoLive.url(id),
                                { approve: true },
                                { preserveScroll: true },
                            )
                        }
                    >
                        {t('Approve')}
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={async () => {
                            const reason = await askText({
                                title: t('What should the hotel change?'),
                                required: true,
                            });
                            if (reason) {
                                router.post(
                                    PlatformSignupController.decideGoLive.url(
                                        id,
                                    ),
                                    { approve: false, reason },
                                    { preserveScroll: true },
                                );
                            }
                        }}
                    >
                        {t('Refuse')}
                    </Button>
                </>
            )}
            <Button
                size="sm"
                variant="ghost"
                className={s.status === 'suspended' ? '' : 'text-destructive'}
                onClick={async () => {
                    const suspend = s.status !== 'suspended';
                    const reason = suspend
                        ? await askText({
                              title: t('Reason for suspending'),
                              required: true,
                          })
                        : '';
                    if (!suspend || reason) {
                        router.post(
                            PlatformSignupController.suspend.url(id),
                            { suspend, reason },
                            { preserveScroll: true },
                        );
                    }
                }}
            >
                {s.status === 'suspended' ? t('Reactivate') : t('Suspend')}
            </Button>
        </div>
    );
}

export default function PlatformSignups({
    settings,
    codes,
    signups,
    templates,
    legal,
    funnel,
}: Props) {
    const tabs = [
        { key: 'hotels', label: t('New hotels'), count: signups.length },
        { key: 'codes', label: t('Invitation codes'), count: codes.length },
        {
            key: 'countries',
            label: t('Country templates'),
            count: templates.length,
        },
        { key: 'legal', label: t('Legal texts') },
        { key: 'settings', label: t('Settings') },
    ];
    const [tab, setTab] = useTabs(tabs);
    const [newCode, setNewCode] = useState(false);
    const [template, setTemplate] = useState<Template | 'new' | null>(null);
    const [doc, setDoc] = useState<Legal | null>(null);

    return (
        <>
            <Head title={t('Sign-ups')} />
            <PageBody>
                <PageHeader
                    title={t('Sign-ups')}
                    description={t(
                        'Hotels that signed up, how they can sign up, and the defaults they start with.',
                    )}
                    actions={
                        tab === 'codes' ? (
                            <Button onClick={() => setNewCode(true)}>
                                <Plus /> {t('New code')}
                            </Button>
                        ) : tab === 'countries' ? (
                            <Button onClick={() => setTemplate('new')}>
                                <Plus /> {t('Add country')}
                            </Button>
                        ) : null
                    }
                />
                <Tabs tabs={tabs} active={tab} onSelect={setTab} />

                {tab === 'hotels' && (
                    <>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-8">
                            {funnel.map((f) => (
                                <StatTile
                                    key={f.key}
                                    label={f.label}
                                    value={f.count}
                                />
                            ))}
                        </div>
                        <Section
                            flush
                            title={t('New hotels')}
                            description={t(
                                'Hotels that signed up on the website, newest first.',
                            )}
                        >
                            <DataTable
                                rows={signups}
                                rowKey={(s) => s.id}
                                empty={t('No sign-ups yet.')}
                                columns={[
                                    {
                                        key: 'hotel',
                                        header: t('Hotel'),
                                        cell: (s) => (
                                            <div>
                                                <div className="font-medium">
                                                    {s.hotel}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {s.city}, {s.country} ·{' '}
                                                    {t(':rooms rooms', {
                                                        rooms: s.rooms,
                                                    })}
                                                </div>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'owner',
                                        header: t('Owner'),
                                        cell: (s) => (
                                            <div>
                                                <div>{s.owner}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    {s.email}
                                                    {!s.verified &&
                                                        ` · ${t('Email not confirmed')}`}
                                                </div>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'status',
                                        header: t('Status'),
                                        cell: (s) => (
                                            <div className="flex flex-wrap gap-1">
                                                {s.status && (
                                                    <Badge
                                                        variant={
                                                            s.status ===
                                                            'suspended'
                                                                ? 'destructive'
                                                                : 'outline'
                                                        }
                                                    >
                                                        {s.status}
                                                    </Badge>
                                                )}
                                                {s.golive?.decision ===
                                                    'approved' && (
                                                    <Badge>{t('Live')}</Badge>
                                                )}
                                                {s.golive?.requested_at &&
                                                    s.golive.decision ===
                                                        null && (
                                                        <Badge variant="destructive">
                                                            {t(
                                                                'Asks to go live',
                                                            )}
                                                        </Badge>
                                                    )}
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'setup',
                                        header: t('Setup'),
                                        cell: (s) =>
                                            s.setup
                                                ? `${s.setup.done}/${s.setup.total}`
                                                : '—',
                                    },
                                    {
                                        key: 'trial',
                                        header: t('Trial until'),
                                        cell: (s) => s.trial_ends ?? '—',
                                    },
                                    {
                                        key: 'actions',
                                        header: '',
                                        align: 'right',
                                        cell: (s) => <SignupActions s={s} />,
                                    },
                                ]}
                            />
                        </Section>
                    </>
                )}

                {tab === 'codes' && (
                    <Section
                        flush
                        title={t('Invitation codes')}
                        description={t(
                            'Needed to sign up while access is set to "Invitation code only".',
                        )}
                    >
                        <DataTable
                            rows={codes}
                            rowKey={(c) => c.id}
                            empty={t('No invitation codes yet.')}
                            columns={[
                                {
                                    key: 'code',
                                    header: t('Code'),
                                    cell: (c) => (
                                        <span className="font-mono font-medium">
                                            {c.code}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'note',
                                    header: t('Note'),
                                    cell: (c) => (
                                        <span className="text-muted-foreground">
                                            {c.note ?? '—'}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'uses',
                                    header: t('Uses'),
                                    align: 'right',
                                    cell: (c) =>
                                        c.max_uses
                                            ? `${c.uses} / ${c.max_uses}`
                                            : c.uses,
                                },
                                {
                                    key: 'expires',
                                    header: t('Expires on'),
                                    cell: (c) => c.expires_at ?? '—',
                                },
                                {
                                    key: 'status',
                                    header: t('Status'),
                                    cell: (c) => (
                                        <Badge
                                            variant={
                                                c.usable ? 'default' : 'outline'
                                            }
                                        >
                                            {c.usable
                                                ? t('Active')
                                                : c.revoked
                                                  ? t('Revoked')
                                                  : t('Used up or expired')}
                                        </Badge>
                                    ),
                                },
                                {
                                    key: 'actions',
                                    header: '',
                                    align: 'right',
                                    cell: (c) =>
                                        !c.revoked && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                className="text-destructive"
                                                onClick={() =>
                                                    router.post(
                                                        PlatformSignupController.revokeCode.url(
                                                            c.id,
                                                        ),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {t('Revoke')}
                                            </Button>
                                        ),
                                },
                            ]}
                        />
                    </Section>
                )}

                {tab === 'countries' && (
                    <Section
                        flush
                        title={t('Country templates')}
                        description={t(
                            'Currency, time zone, taxes and room categories a new hotel starts with. Click a country to edit it.',
                        )}
                    >
                        <DataTable
                            rows={templates}
                            rowKey={(tpl) => tpl.id}
                            empty={t('No country yet.')}
                            onRowClick={setTemplate}
                            columns={[
                                {
                                    key: 'country',
                                    header: t('Country'),
                                    cell: (tpl) => (
                                        <div className="flex items-center gap-2">
                                            <span className="w-7 font-mono text-xs text-muted-foreground">
                                                {tpl.country_code}
                                            </span>
                                            <span className="font-medium">
                                                {tpl.name}
                                            </span>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'currency',
                                    header: t('Currency'),
                                    cell: (tpl) => tpl.currency,
                                },
                                {
                                    key: 'taxes',
                                    header: t('Taxes'),
                                    cell: (tpl) =>
                                        tpl.taxes
                                            .map((x) => `${x.name} ${x.rate}%`)
                                            .join(', ') || '—',
                                },
                                {
                                    key: 'locale',
                                    header: t('Language'),
                                    cell: (tpl) => tpl.locale.toUpperCase(),
                                },
                                {
                                    key: 'status',
                                    header: t('Status'),
                                    cell: (tpl) => (
                                        <Badge
                                            variant={
                                                tpl.active
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                        >
                                            {tpl.active
                                                ? t('Offered')
                                                : t('Off')}
                                        </Badge>
                                    ),
                                },
                                {
                                    key: 'edit',
                                    header: '',
                                    align: 'right',
                                    cell: () => (
                                        <span className="text-xs text-brand">
                                            {t('Edit')}
                                        </span>
                                    ),
                                },
                            ]}
                        />
                    </Section>
                )}

                {tab === 'legal' && (
                    <Section
                        flush
                        title={t('Legal texts')}
                        description={t(
                            'Hotels accept them at sign-up. Publishing a new version asks owners to accept it again.',
                        )}
                    >
                        <DataTable
                            rows={legal}
                            rowKey={(d) => d.kind}
                            empty="—"
                            onRowClick={setDoc}
                            columns={[
                                {
                                    key: 'label',
                                    header: t('Document'),
                                    cell: (d) => (
                                        <span className="font-medium">
                                            {d.label}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'version',
                                    header: t('Version'),
                                    cell: (d) => d.version ?? '—',
                                },
                                {
                                    key: 'edit',
                                    header: '',
                                    align: 'right',
                                    cell: () => (
                                        <span className="text-xs text-brand">
                                            {t('Edit')}
                                        </span>
                                    ),
                                },
                            ]}
                        />
                    </Section>
                )}

                {tab === 'settings' && <SettingsTab settings={settings} />}
            </PageBody>

            <CodeEditor open={newCode} onClose={() => setNewCode(false)} />
            <TemplateEditor
                template={template}
                onClose={() => setTemplate(null)}
            />
            <LegalEditor doc={doc} onClose={() => setDoc(null)} />
        </>
    );
}

PlatformSignups.layout = {
    breadcrumbs: [
        { title: 'Sign-ups', href: PlatformSignupController.index() },
    ],
};
