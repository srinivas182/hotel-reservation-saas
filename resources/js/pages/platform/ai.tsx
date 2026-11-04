import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import AiController from '@/actions/App/Domains/Platform/Http/Controllers/AiController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
        <form
            onSubmit={submit}
            className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2"
        >
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
                <Input
                    id="p-key"
                    type="password"
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
            <div className="flex gap-2 sm:col-span-2">
                <Button disabled={form.processing}>{t('Save')}</Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
            </div>
        </form>
    );
}

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
        <form
            onSubmit={submit}
            className="grid gap-3 rounded-xl border p-4 sm:grid-cols-3"
        >
            <div className="grid gap-1">
                <Label htmlFor="m-provider">{t('Provider')}</Label>
                <select
                    id="m-provider"
                    className={selectClass}
                    value={form.data.ai_provider_id}
                    onChange={(e) =>
                        form.setData('ai_provider_id', Number(e.target.value))
                    }
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
                <Input
                    id="m-model"
                    value={form.data.model}
                    onChange={(e) => form.setData('model', e.target.value)}
                />
                <InputError message={errors.model} />
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
            <div className="flex items-end gap-2">
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
            className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2"
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
        number | 'new' | null
    >(null);
    const [editingModel, setEditingModel] = useState<number | 'new' | null>(
        null,
    );

    return (
        <>
            <Head title="AI" />
            <div className="flex max-w-5xl flex-col gap-8 p-4">
                <Heading
                    title="AI"
                    description={t(
                        'AI cost across all hotels this month: US$ :cost',
                        { cost: monthCost },
                    )}
                />

                {settings.kill_switch && (
                    <p className="rounded-lg border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive">
                        {t('AI is switched off for every hotel.')}
                    </p>
                )}

                <section className="flex flex-col gap-3">
                    <Heading variant="small" title={t('Models used')} />
                    <SettingsForm settings={settings} models={models} />
                </section>

                <section className="flex flex-col gap-3">
                    <div className="flex items-center justify-between">
                        <Heading variant="small" title={t('Providers')} />
                        {editingProvider === null && (
                            <Button
                                variant="outline"
                                onClick={() => setEditingProvider('new')}
                            >
                                {t('Add provider')}
                            </Button>
                        )}
                    </div>
                    {editingProvider === 'new' && (
                        <ProviderForm
                            drivers={drivers}
                            onDone={() => setEditingProvider(null)}
                        />
                    )}
                    <ul className="flex flex-col gap-2">
                        {providers.map((provider) =>
                            editingProvider === provider.id ? (
                                <li key={provider.id}>
                                    <ProviderForm
                                        provider={provider}
                                        drivers={drivers}
                                        onDone={() => setEditingProvider(null)}
                                    />
                                </li>
                            ) : (
                                <li
                                    key={provider.id}
                                    className="flex flex-wrap items-center gap-2 rounded-xl border px-4 py-3 text-sm"
                                >
                                    <span className="font-medium">
                                        {provider.name}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {provider.driver_label} ·{' '}
                                        {t('key :hint', {
                                            hint: provider.key_hint ?? '—',
                                        })}
                                    </span>
                                    {!provider.active && (
                                        <Badge variant="secondary">
                                            {t('Inactive')}
                                        </Badge>
                                    )}
                                    <div className="ml-auto flex gap-1">
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                setEditingProvider(provider.id)
                                            }
                                        >
                                            {t('Edit')}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="text-destructive"
                                            onClick={() =>
                                                window.confirm(
                                                    t('Delete :name?', {
                                                        name: provider.name,
                                                    }),
                                                ) &&
                                                router.delete(
                                                    AiController.destroyProvider.url(
                                                        provider.id,
                                                    ),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('Delete')}
                                        </Button>
                                    </div>
                                </li>
                            ),
                        )}
                    </ul>
                </section>

                <section className="flex flex-col gap-3">
                    <div className="flex items-center justify-between">
                        <Heading
                            variant="small"
                            title={t('Approved model list')}
                            description={t(
                                'A model is approved only after it passes the test suite.',
                            )}
                        />
                        {editingModel === null && providers.length > 0 && (
                            <Button
                                variant="outline"
                                onClick={() => setEditingModel('new')}
                            >
                                {t('Add model')}
                            </Button>
                        )}
                    </div>
                    {editingModel === 'new' && (
                        <ModelForm
                            providers={providers}
                            statuses={statuses}
                            onDone={() => setEditingModel(null)}
                        />
                    )}
                    <ul className="flex flex-col gap-2">
                        {models.map((model) =>
                            editingModel === model.id ? (
                                <li key={model.id}>
                                    <ModelForm
                                        model={model}
                                        providers={providers}
                                        statuses={statuses}
                                        onDone={() => setEditingModel(null)}
                                    />
                                </li>
                            ) : (
                                <li
                                    key={model.id}
                                    className="flex flex-wrap items-center gap-2 rounded-xl border px-4 py-3 text-sm"
                                >
                                    <span className="font-medium">
                                        {model.label}
                                    </span>
                                    <code className="text-xs">
                                        {model.model}
                                    </code>
                                    <span className="text-muted-foreground">
                                        {model.provider}
                                    </span>
                                    <Badge
                                        variant={
                                            model.status === 'approved'
                                                ? 'default'
                                                : 'secondary'
                                        }
                                    >
                                        {model.status_label}
                                    </Badge>
                                    <span className="text-xs text-muted-foreground">
                                        {t(
                                            'US$ :in / :out per million tokens',
                                            {
                                                in: model.input_cost,
                                                out: model.output_cost,
                                            },
                                        )}
                                    </span>
                                    <div className="ml-auto flex gap-1">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    AiController.testModel.url(
                                                        model.id,
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
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
                                                        { name: model.label },
                                                    ),
                                                ) &&
                                                router.post(
                                                    AiController.evaluate.url(
                                                        model.id,
                                                    ),
                                                    { approve: true },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('Run test suite')}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                setEditingModel(model.id)
                                            }
                                        >
                                            {t('Edit')}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="text-destructive"
                                            onClick={() =>
                                                window.confirm(
                                                    t('Delete :name?', {
                                                        name: model.label,
                                                    }),
                                                ) &&
                                                router.delete(
                                                    AiController.destroyModel.url(
                                                        model.id,
                                                    ),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('Delete')}
                                        </Button>
                                    </div>
                                </li>
                            ),
                        )}
                    </ul>
                </section>

                <section className="flex flex-col gap-3">
                    <Heading
                        variant="small"
                        title={t('Test suite runs')}
                        description={t(
                            'Approval needs at least 95% of all cases and every safety case.',
                        )}
                    />
                    {evalRuns.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('No runs yet.')}
                        </p>
                    )}
                    <ul className="flex flex-col gap-2">
                        {evalRuns.map((run) => (
                            <li
                                key={run.id}
                                className="rounded-xl border px-4 py-3 text-sm"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">
                                        {run.model}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {run.started_at}
                                    </span>
                                    <span>
                                        {t(
                                            ':passed/:total passed · safety :safety_passed/:safety_total · US$ :cost',
                                            {
                                                passed: run.passed,
                                                total: run.total,
                                                safety_passed:
                                                    run.safety_passed,
                                                safety_total: run.safety_total,
                                                cost: run.cost,
                                            },
                                        )}
                                    </span>
                                    <Badge
                                        variant={
                                            run.meets_threshold
                                                ? 'default'
                                                : 'destructive'
                                        }
                                    >
                                        {run.meets_threshold
                                            ? t('Meets the threshold')
                                            : t('Below the threshold')}
                                    </Badge>
                                </div>
                                {run.failures.length > 0 && (
                                    <details className="mt-2">
                                        <summary>
                                            {t(':count failed case(s)', {
                                                count: run.failures.length,
                                            })}
                                        </summary>
                                        <ul className="mt-1 flex flex-col gap-1">
                                            {run.failures.map((failure) => (
                                                <li key={failure.id}>
                                                    <code className="text-xs">
                                                        {failure.id}
                                                    </code>
                                                    {failure.safety && (
                                                        <Badge
                                                            variant="destructive"
                                                            className="ml-1"
                                                        >
                                                            {t('safety')}
                                                        </Badge>
                                                    )}{' '}
                                                    {failure.failures.join(' ')}
                                                    {failure.reply && (
                                                        <div className="text-xs text-muted-foreground">
                                                            {failure.reply}
                                                        </div>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    </details>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}

PlatformAi.layout = {
    breadcrumbs: [{ title: 'AI', href: AiController.index() }],
};
