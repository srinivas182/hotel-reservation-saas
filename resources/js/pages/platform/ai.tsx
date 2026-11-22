import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import AiController from '@/actions/App/Domains/Platform/Http/Controllers/AiController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PasswordInput from '@/components/password-input';
import {
    DataTable,
    PageBody,
    PageHeader,
    Section,
    SidePanel,
    StatTile,
    Tabs,
    useTabs,
} from '@/components/page';
import { t } from '@/lib/i18n';

type Provider = {
    id: number;
    name: string;
    driver: string;
    driver_label: string;
    url: string | null;
    key_hint: string | null;
    active: boolean;
};
type Model = {
    id: number;
    ai_provider_id: number;
    provider: string | null;
    model: string;
    label: string;
    capabilities: string[];
    status: string;
    status_label: string;
    input_cost: number;
    output_cost: number;
    audio_cost: number;
    certified_at: string | null;
};
type Settings = {
    assistant_model_id: number | null;
    assistant_fallback_id: number | null;
    transcription_model_id: number | null;
    transcription_fallback_id: number | null;
    timeout_seconds: number;
    kill_switch: boolean;
};
type Props = {
    providers: Provider[];
    models: Model[];
    settings: Settings;
    drivers: { value: string; label: string; needs_url: boolean }[];
    statuses: { value: string; label: string }[];
    monthCost: number;
    evalRuns: EvalRun[];
};

type EvalRun = {
    id: number;
    model: string | null;
    started_at: string;
    finished: boolean;
    passed: number;
    total: number;
    safety_passed: number;
    safety_total: number;
    meets_threshold: boolean;
    cost: number;
    failures: {
        id: string;
        safety: boolean;
        failures: string[];
        reply: string | null;
    }[];
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

function ProviderForm({
    provider,
    drivers,
    onDone,
}: {
    provider?: Provider;
    drivers: Props['drivers'];
    onDone: () => void;
}) {
    const form = useForm({
        name: provider?.name ?? '',
        driver: provider?.driver ?? drivers[0].value,
        key: '',
        url: provider?.url ?? '',
        active: provider?.active ?? true,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const needsUrl =
        drivers.find((d) => d.value === form.data.driver)?.needs_url ?? false;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };

        if (provider) {
            form.put(AiController.updateProvider.url(provider.id), options);
        } else {
            form.post(AiController.storeProvider.url(), options);
        }
    };

    return (
        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-1">
                <Label htmlFor="p-name">{t('Name')}</Label>
                <Input
                    id="p-name"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
                <InputError message={errors.name} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="p-driver">{t('Provider')}</Label>
                <select
                    id="p-driver"
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
            <div className="grid gap-1">
                <Label htmlFor="p-key">
                    {provider
                        ? t('API key (leave empty to keep :hint)', {
                              hint: provider.key_hint ?? '',
                          })
                        : t('API key')}
                </Label>
                <PasswordInput
                    id="p-key"
                    autoComplete="off"
                    value={form.data.key}
                    onChange={(e) => form.setData('key', e.target.value)}
                />
                <InputError message={errors.key} />
            </div>
            {needsUrl && (
                <div className="grid gap-1">
                    <Label htmlFor="p-url">{t('Address (https)')}</Label>
                    <Input
                        id="p-url"
                        value={form.data.url}
                        onChange={(e) => form.setData('url', e.target.value)}
                    />
                    <InputError message={errors.url} />
                </div>
            )}
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={form.data.active}
                    onChange={(e) => form.setData('active', e.target.checked)}
                />
                {t('Active')}
            </label>
            <div className="flex justify-end gap-2 border-t pt-4 sm:col-span-2">
                <Button disabled={form.processing}>{t('Save')}</Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
            </div>
        </form>
    );
}

type CatalogModel = {
    id: string;
    label: string;
    capabilities: string[];
    added: boolean;
};

function ModelForm({
    model,
    providers,
    statuses,
    onDone,
}: {
    model?: Model;
    providers: Provider[];
    statuses: Props['statuses'];
    onDone: () => void;
}) {
    const form = useForm({
        ai_provider_id: model?.ai_provider_id ?? providers[0]?.id ?? 0,
        model: model?.model ?? '',
        label: model?.label ?? '',
        capabilities: model?.capabilities ?? ['chat', 'tools'],
        status: model?.status ?? 'testing',
        input_cost: model?.input_cost ?? 0,
        output_cost: model?.output_cost ?? 0,
        audio_cost: model?.audio_cost ?? 0,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const [catalog, setCatalog] = useState<CatalogModel[] | null>(null);
    const [loading, setLoading] = useState(false);
    const [loadError, setLoadError] = useState<string | null>(null);

    const loadModels = async () => {
        setLoading(true);
        setLoadError(null);
        try {
            const response = await fetch(
                AiController.providerModels.url(form.data.ai_provider_id),
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            );
            const body = (await response.json()) as {
                models?: CatalogModel[];
                message?: string;
            };
            if (!response.ok || !body.models) {
                setLoadError(
                    body.message ?? t('The models could not be loaded.'),
                );
                setCatalog(null);
            } else {
                setCatalog(body.models);
            }
        } catch {
            setLoadError(t('The models could not be loaded.'));
        } finally {
            setLoading(false);
        }
    };

    const pick = (id: string) => {
        const found = catalog?.find((m) => m.id === id);
        if (!found) {
            return;
        }
        form.setData((data) => ({
            ...data,
            model: found.id,
            label: data.label === '' ? found.label : data.label,
            capabilities: found.capabilities,
        }));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };

        if (model) {
            form.put(AiController.updateModel.url(model.id), options);
        } else {
            form.post(AiController.storeModel.url(), options);
        }
    };

    const toggle = (capability: string) =>
        form.setData(
            'capabilities',
            form.data.capabilities.includes(capability)
                ? form.data.capabilities.filter((c) => c !== capability)
                : [...form.data.capabilities, capability],
        );

    return (
        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-1">
                <Label htmlFor="m-provider">{t('Provider')}</Label>
                <select
                    id="m-provider"
                    className={selectClass}
                    value={form.data.ai_provider_id}
                    onChange={(e) => {
                        form.setData('ai_provider_id', Number(e.target.value));
                        setCatalog(null);
                    }}
                >
                    {providers.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.name}
                        </option>
                    ))}
                </select>
            </div>
            <div className="grid gap-1">
                <Label htmlFor="m-model">{t('Model ID')}</Label>
                {catalog && catalog.length > 0 ? (
                    <select
                        id="m-model"
                        className={selectClass}
                        value={form.data.model}
                        onChange={(e) => pick(e.target.value)}
                    >
                        <option value="">{t('Choose a model…')}</option>
                        {catalog.map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.label === m.id
                                    ? m.id
                                    : `${m.label} (${m.id})`}
                                {m.added ? ` · ${t('added')}` : ''}
                            </option>
                        ))}
                    </select>
                ) : (
                    <Input
                        id="m-model"
                        value={form.data.model}
                        onChange={(e) => form.setData('model', e.target.value)}
                    />
                )}
                {!model && (
                    <button
                        type="button"
                        className="w-fit text-xs text-brand-ink underline disabled:opacity-50"
                        disabled={loading || !form.data.ai_provider_id}
                        onClick={loadModels}
                    >
                        {loading
                            ? t('Loading models…')
                            : catalog
                              ? t('Reload the list')
                              : t('Load models from the provider')}
                    </button>
                )}
                {catalog && catalog.length === 0 && (
                    <span className="text-xs text-muted-foreground">
                        {t('The provider returned no usable models.')}
                    </span>
                )}
                <InputError message={loadError ?? errors.model} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="m-label">{t('Label')}</Label>
                <Input
                    id="m-label"
                    value={form.data.label}
                    onChange={(e) => form.setData('label', e.target.value)}
                />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="m-in">
                    {t('Input US$ per million tokens')}
                </Label>
                <Input
                    id="m-in"
                    type="number"
                    step="any"
                    min={0}
                    value={form.data.input_cost}
                    onChange={(e) =>
                        form.setData('input_cost', Number(e.target.value))
                    }
                />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="m-out">
                    {t('Output US$ per million tokens')}
                </Label>
                <Input
                    id="m-out"
                    type="number"
                    step="any"
                    min={0}
                    value={form.data.output_cost}
                    onChange={(e) =>
                        form.setData('output_cost', Number(e.target.value))
                    }
                />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="m-audio">{t('Audio US$ per minute')}</Label>
                <Input
                    id="m-audio"
                    type="number"
                    step="any"
                    min={0}
                    value={form.data.audio_cost}
                    onChange={(e) =>
                        form.setData('audio_cost', Number(e.target.value))
                    }
                />
            </div>
            <div className="flex flex-wrap gap-3 text-sm">
                {['chat', 'tools', 'transcription'].map((c) => (
                    <label key={c} className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={form.data.capabilities.includes(c)}
                            onChange={() => toggle(c)}
                        />
                        {t(c)}
                    </label>
                ))}
            </div>
            <div className="grid gap-1">
                <Label htmlFor="m-status">{t('Status')}</Label>
                <select
                    id="m-status"
                    className={selectClass}
                    value={form.data.status}
                    onChange={(e) => form.setData('status', e.target.value)}
                >
                    {statuses.map((s) => (
                        <option key={s.value} value={s.value}>
                            {s.label}
                        </option>
                    ))}
                </select>
            </div>
            <div className="flex justify-end gap-2 border-t pt-4 sm:col-span-2">
                <Button disabled={form.processing}>{t('Save')}</Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
            </div>
        </form>
    );
}

function SettingsForm({
    settings,
    models,
}: {
    settings: Settings;
    models: Model[];
}) {
    const form = useForm(settings);
    const errors = form.errors as Record<string, string | undefined>;
    const usable = models.filter((m) => m.status !== 'retired');

    const select = (key: keyof Settings, label: string, capability: string) => (
        <div className="grid gap-1">
            <Label htmlFor={key}>{label}</Label>
            <select
                id={key}
                className={selectClass}
                value={(form.data[key] as number | null) ?? ''}
                onChange={(e) =>
                    form.setData(
                        key,
                        e.target.value === '' ? null : Number(e.target.value),
                    )
                }
            >
                <option value="">—</option>
                {usable
                    .filter((m) => m.capabilities.includes(capability))
                    .map((m) => (
                        <option key={m.id} value={m.id}>
                            {m.label} ({m.provider})
                            {m.status !== 'approved'
                                ? ` · ${m.status_label}`
                                : ''}
                        </option>
                    ))}
            </select>
            <InputError message={errors[key]} />
        </div>
    );

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.put(AiController.updateSettings.url(), {
                    preserveScroll: true,
                });
            }}
            className="grid gap-4 sm:grid-cols-2"
        >
            {select('assistant_model_id', t('Assistant: main model'), 'chat')}
            {select(
                'assistant_fallback_id',
                t('Assistant: backup model'),
                'chat',
            )}
            {select(
                'transcription_model_id',
                t('Voice notes: main model'),
                'transcription',
            )}
            {select(
                'transcription_fallback_id',
                t('Voice notes: backup model'),
                'transcription',
            )}
            <div className="grid gap-1">
                <Label htmlFor="timeout">
                    {t('Time limit per request (seconds)')}
                </Label>
                <Input
                    id="timeout"
                    type="number"
                    min={5}
                    max={120}
                    value={form.data.timeout_seconds}
                    onChange={(e) =>
                        form.setData('timeout_seconds', Number(e.target.value))
                    }
                />
            </div>
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={form.data.kill_switch}
                    onChange={(e) =>
                        form.setData('kill_switch', e.target.checked)
                    }
                />
                <span
                    className={
                        form.data.kill_switch
                            ? 'font-medium text-destructive'
                            : ''
                    }
                >
                    {t('Switch AI off for every hotel')}
                </span>
            </label>
            <div className="sm:col-span-2">
                <Button disabled={form.processing}>{t('Save settings')}</Button>
            </div>
        </form>
    );
}

export default function PlatformAi({
    providers,
    models,
    settings,
    drivers,
    statuses,
    monthCost,
    evalRuns,
}: Props) {
    const [editingProvider, setEditingProvider] = useState<
        Provider | 'new' | null
    >(null);
    const [editingModel, setEditingModel] = useState<Model | 'new' | null>(
        null,
    );
    const tabs = [
        { key: 'settings', label: t('Models used') },
        { key: 'providers', label: t('Providers'), count: providers.length },
        { key: 'models', label: t('Model list'), count: models.length },
        { key: 'runs', label: t('Test suite runs'), count: evalRuns.length },
    ];
    const [tab, setTab] = useTabs(tabs);
    const approved = models.filter((m) => m.status === 'approved').length;
    const missingKeys = providers.filter((p) => p.key_hint === null).length;

    return (
        <>
            <Head title="AI" />
            <PageBody>
                <PageHeader
                    title="AI"
                    description={t(
                        'Which AI answers guests, with which keys, and what it costs.',
                    )}
                    actions={
                        tab === 'providers' ? (
                            <Button onClick={() => setEditingProvider('new')}>
                                {t('Add provider')}
                            </Button>
                        ) : tab === 'models' && providers.length > 0 ? (
                            <Button onClick={() => setEditingModel('new')}>
                                {t('Add model')}
                            </Button>
                        ) : null
                    }
                />

                {settings.kill_switch && (
                    <p className="rounded-lg border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                        {t('AI is switched off for every hotel.')}
                    </p>
                )}

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatTile
                        label={t('AI cost this month')}
                        value={`US$ ${monthCost}`}
                        hint={t('All hotels')}
                    />
                    <StatTile
                        label={t('Approved models')}
                        value={`${approved} / ${models.length}`}
                    />
                    <StatTile
                        label={t('Providers')}
                        value={providers.length}
                        hint={
                            missingKeys > 0
                                ? t(':count without a key', {
                                      count: missingKeys,
                                  })
                                : undefined
                        }
                    />
                    <StatTile
                        label={t('Time limit per request')}
                        value={`${settings.timeout_seconds} s`}
                    />
                </div>

                <Tabs tabs={tabs} active={tab} onSelect={setTab} />

                {tab === 'settings' && (
                    <Section
                        aside
                        title={t('Models used')}
                        description={t(
                            'The main model answers; the backup takes over when it fails or is too slow. Only models that passed the test suite should be main models.',
                        )}
                    >
                        <SettingsForm settings={settings} models={models} />
                    </Section>
                )}

                {tab === 'providers' && (
                    <Section
                        flush
                        title={t('Providers')}
                        description={t(
                            'AI accounts the platform pays for. Keys are encrypted and never shown again.',
                        )}
                    >
                        <DataTable
                            rows={providers}
                            rowKey={(p) => p.id}
                            empty={t('No provider yet.')}
                            onRowClick={(p) => setEditingProvider(p)}
                            columns={[
                                {
                                    key: 'name',
                                    header: t('Name'),
                                    cell: (p) => (
                                        <span className="font-medium">
                                            {p.name}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'driver',
                                    header: t('Provider'),
                                    cell: (p) => p.driver_label,
                                },
                                {
                                    key: 'key',
                                    header: t('Key'),
                                    cell: (p) =>
                                        p.key_hint === null ? (
                                            <Badge variant="destructive">
                                                {t(
                                                    'No key: edit and enter it again',
                                                )}
                                            </Badge>
                                        ) : (
                                            <code className="text-xs text-muted-foreground">
                                                {p.key_hint}
                                            </code>
                                        ),
                                },
                                {
                                    key: 'status',
                                    header: t('Status'),
                                    cell: (p) =>
                                        p.active ? (
                                            <Badge>{t('Active')}</Badge>
                                        ) : (
                                            <Badge variant="outline">
                                                {t('Inactive')}
                                            </Badge>
                                        ),
                                },
                                {
                                    key: 'actions',
                                    header: '',
                                    align: 'right',
                                    cell: (p) => (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="text-destructive"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                if (
                                                    window.confirm(
                                                        t('Delete :name?', {
                                                            name: p.name,
                                                        }),
                                                    )
                                                ) {
                                                    router.delete(
                                                        AiController.destroyProvider.url(
                                                            p.id,
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }
                                            }}
                                        >
                                            {t('Delete')}
                                        </Button>
                                    ),
                                },
                            ]}
                        />
                    </Section>
                )}

                {tab === 'models' && (
                    <Section
                        flush
                        title={t('Model list')}
                        description={t(
                            'A model is approved only after it passes the test suite.',
                        )}
                    >
                        <DataTable
                            rows={models}
                            rowKey={(m) => m.id}
                            empty={t(
                                'No model yet: add a provider, then a model.',
                            )}
                            onRowClick={(m) => setEditingModel(m)}
                            columns={[
                                {
                                    key: 'label',
                                    header: t('Model'),
                                    cell: (m) => (
                                        <div>
                                            <div className="font-medium">
                                                {m.label}
                                            </div>
                                            <code className="text-xs text-muted-foreground">
                                                {m.model}
                                            </code>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'provider',
                                    header: t('Provider'),
                                    cell: (m) => m.provider ?? '—',
                                },
                                {
                                    key: 'uses',
                                    header: t('Can'),
                                    cell: (m) => (
                                        <div className="flex flex-wrap gap-1">
                                            {m.capabilities.map((c) => (
                                                <Badge
                                                    key={c}
                                                    variant="outline"
                                                >
                                                    {t(c)}
                                                </Badge>
                                            ))}
                                        </div>
                                    ),
                                },
                                {
                                    key: 'cost',
                                    header: t(
                                        'US$ per million tokens (in / out)',
                                    ),
                                    align: 'right',
                                    cell: (m) =>
                                        `${m.input_cost} / ${m.output_cost}`,
                                },
                                {
                                    key: 'status',
                                    header: t('Status'),
                                    cell: (m) => (
                                        <Badge
                                            variant={
                                                m.status === 'approved'
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {m.status_label}
                                        </Badge>
                                    ),
                                },
                                {
                                    key: 'actions',
                                    header: '',
                                    align: 'right',
                                    cell: (m) => (
                                        <div
                                            className="flex justify-end gap-1"
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        AiController.testModel.url(
                                                            m.id,
                                                        ),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {t('Test')}
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    window.confirm(
                                                        t(
                                                            'Run the full test suite against :name? It calls the real model about 65 times and costs money. Approve the model automatically if it passes?',
                                                            { name: m.label },
                                                        ),
                                                    ) &&
                                                    router.post(
                                                        AiController.evaluate.url(
                                                            m.id,
                                                        ),
                                                        { approve: true },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {t('Run test suite')}
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                className="text-destructive"
                                                onClick={() =>
                                                    window.confirm(
                                                        t('Delete :name?', {
                                                            name: m.label,
                                                        }),
                                                    ) &&
                                                    router.delete(
                                                        AiController.destroyModel.url(
                                                            m.id,
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {t('Delete')}
                                            </Button>
                                        </div>
                                    ),
                                },
                            ]}
                        />
                    </Section>
                )}

                {tab === 'runs' && (
                    <Section
                        flush
                        title={t('Test suite runs')}
                        description={t(
                            'Approval needs at least 95% of all cases and every safety case.',
                        )}
                    >
                        <DataTable
                            rows={evalRuns}
                            rowKey={(r) => r.id}
                            empty={t('No runs yet.')}
                            columns={[
                                {
                                    key: 'model',
                                    header: t('Model'),
                                    cell: (r) => (
                                        <span className="font-medium">
                                            {r.model}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'at',
                                    header: t('Started'),
                                    cell: (r) => r.started_at,
                                },
                                {
                                    key: 'passed',
                                    header: t('Passed'),
                                    align: 'right',
                                    cell: (r) =>
                                        r.finished
                                            ? `${r.passed} / ${r.total}`
                                            : t('Running…'),
                                },
                                {
                                    key: 'safety',
                                    header: t('Safety'),
                                    align: 'right',
                                    cell: (r) =>
                                        `${r.safety_passed} / ${r.safety_total}`,
                                },
                                {
                                    key: 'cost',
                                    header: 'US$',
                                    align: 'right',
                                    cell: (r) => r.cost,
                                },
                                {
                                    key: 'result',
                                    header: t('Result'),
                                    cell: (r) => (
                                        <div>
                                            <Badge
                                                variant={
                                                    r.meets_threshold
                                                        ? 'default'
                                                        : 'destructive'
                                                }
                                            >
                                                {r.meets_threshold
                                                    ? t('Meets the threshold')
                                                    : t('Below the threshold')}
                                            </Badge>
                                            {r.failures.length > 0 && (
                                                <details className="mt-2 text-xs">
                                                    <summary className="cursor-pointer text-muted-foreground">
                                                        {t(
                                                            ':count failed case(s)',
                                                            {
                                                                count: r
                                                                    .failures
                                                                    .length,
                                                            },
                                                        )}
                                                    </summary>
                                                    <ul className="mt-1 grid gap-1.5">
                                                        {r.failures.map((f) => (
                                                            <li key={f.id}>
                                                                <code>
                                                                    {f.id}
                                                                </code>
                                                                {f.safety && (
                                                                    <Badge
                                                                        variant="destructive"
                                                                        className="ml-1"
                                                                    >
                                                                        {t(
                                                                            'safety',
                                                                        )}
                                                                    </Badge>
                                                                )}{' '}
                                                                {f.failures.join(
                                                                    ' ',
                                                                )}
                                                                {f.reply && (
                                                                    <div className="text-muted-foreground">
                                                                        {
                                                                            f.reply
                                                                        }
                                                                    </div>
                                                                )}
                                                            </li>
                                                        ))}
                                                    </ul>
                                                </details>
                                            )}
                                        </div>
                                    ),
                                },
                            ]}
                        />
                    </Section>
                )}
            </PageBody>

            {editingProvider !== null && (
                <SidePanel
                    open
                    onClose={() => setEditingProvider(null)}
                    title={
                        editingProvider === 'new'
                            ? t('Add provider')
                            : editingProvider.name
                    }
                    description={t(
                        'An AI account the platform pays for. The key is encrypted and never shown again.',
                    )}
                >
                    <ProviderForm
                        provider={
                            editingProvider === 'new'
                                ? undefined
                                : editingProvider
                        }
                        drivers={drivers}
                        onDone={() => setEditingProvider(null)}
                    />
                </SidePanel>
            )}
            {editingModel !== null && (
                <SidePanel
                    open
                    onClose={() => setEditingModel(null)}
                    title={
                        editingModel === 'new'
                            ? t('Add model')
                            : editingModel.label
                    }
                    description={t(
                        "Prices are used to work out each hotel's AI cost.",
                    )}
                >
                    <ModelForm
                        model={
                            editingModel === 'new' ? undefined : editingModel
                        }
                        providers={providers}
                        statuses={statuses}
                        onDone={() => setEditingModel(null)}
                    />
                </SidePanel>
            )}
        </>
    );
}

PlatformAi.layout = {
    breadcrumbs: [{ title: 'AI', href: AiController.index() }],
};
