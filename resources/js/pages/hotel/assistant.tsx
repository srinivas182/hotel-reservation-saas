import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AssistantSettingsController from '@/actions/App/Domains/Assistant/Http/Controllers/AssistantSettingsController';
import KnowledgeController from '@/actions/App/Domains/Assistant/Http/Controllers/KnowledgeController';
import PracticeController from '@/actions/App/Domains/Assistant/Http/Controllers/PracticeController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

type Props = {
    settings: {
        enabled: boolean;
        display_name: string;
        tone: string;
        languages: string[];
        handover_messages: Record<string, string>;
        daily_budget: number;
        kill_switch: boolean;
    };
    enabledLocales: { code: string; label: string }[];
    spentToday: number;
    publishedVersion: number | null;
};

export default function AssistantSettings({
    settings,
    enabledLocales,
    spentToday,
    publishedVersion,
}: Props) {
    const form = useForm(settings);
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(AssistantSettingsController.update.url(), {
            preserveScroll: true,
        });
    };

    const toggleLanguage = (code: string) =>
        form.setData(
            'languages',
            form.data.languages.includes(code)
                ? form.data.languages.filter((c) => c !== code)
                : [...form.data.languages, code],
        );

    return (
        <>
            <Head title={t('AI assistant')} />
            <div className="flex max-w-3xl flex-col gap-6 p-4">
                <Heading
                    title={t('AI assistant')}
                    description={t(
                        'Answers guests on WhatsApp with the hotel’s real prices and availability, and passes them to staff when needed.',
                    )}
                />

                <div className="grid gap-3 sm:grid-cols-3">
                    <Link
                        href={KnowledgeController.show()}
                        className="rounded-xl border p-4 text-sm hover:bg-muted/50"
                    >
                        <div className="font-medium">
                            {t('Information pack')}
                        </div>
                        <div className="text-muted-foreground">
                            {publishedVersion
                                ? t('Version :version published', {
                                      version: publishedVersion,
                                  })
                                : t('Not published yet')}
                        </div>
                    </Link>
                    <Link
                        href={PracticeController.show()}
                        className="rounded-xl border p-4 text-sm hover:bg-muted/50"
                    >
                        <div className="font-medium">{t('Practice')}</div>
                        <div className="text-muted-foreground">
                            {t('Try the assistant. Nothing is sent to guests.')}
                        </div>
                    </Link>
                    <div className="rounded-xl border p-4 text-sm">
                        <div className="font-medium">{t('Spent today')}</div>
                        <div className="text-muted-foreground">
                            US$ {spentToday} / {form.data.daily_budget}
                        </div>
                    </div>
                </div>

                {!publishedVersion && (
                    <p className="rounded-lg border px-3 py-2 text-sm">
                        {t(
                            'Publish the information pack before the assistant can answer guests.',
                        )}
                    </p>
                )}

                <form
                    onSubmit={submit}
                    className="flex flex-col gap-4 rounded-xl border p-4"
                >
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={form.data.enabled}
                            onChange={(e) =>
                                form.setData('enabled', e.target.checked)
                            }
                        />
                        {t('Answer guests on WhatsApp')}
                        <Badge variant="secondary">
                            {t('from the WhatsApp go-live')}
                        </Badge>
                    </label>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-1">
                            <Label htmlFor="display_name">
                                {t('Name the assistant uses')}
                            </Label>
                            <Input
                                id="display_name"
                                placeholder={t(
                                    'For example: the assistant of First Class Lodge',
                                )}
                                value={form.data.display_name}
                                onChange={(e) =>
                                    form.setData('display_name', e.target.value)
                                }
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="tone">{t('Tone')}</Label>
                            <select
                                id="tone"
                                className="flex h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                value={form.data.tone}
                                onChange={(e) =>
                                    form.setData('tone', e.target.value)
                                }
                            >
                                <option value="formal">
                                    {t('Polite and formal')}
                                </option>
                                <option value="friendly">
                                    {t('Warm and friendly')}
                                </option>
                            </select>
                        </div>
                    </div>
                    <fieldset className="flex flex-col gap-2 text-sm">
                        <legend className="mb-1 font-medium">
                            {t('Languages the assistant answers in')}
                        </legend>
                        <div className="flex flex-wrap gap-4">
                            {enabledLocales.map((locale) => (
                                <label
                                    key={locale.code}
                                    className="flex items-center gap-2"
                                >
                                    <input
                                        type="checkbox"
                                        className="size-4"
                                        checked={form.data.languages.includes(
                                            locale.code,
                                        )}
                                        onChange={() =>
                                            toggleLanguage(locale.code)
                                        }
                                    />
                                    {locale.label}
                                </label>
                            ))}
                        </div>
                        <InputError message={errors.languages} />
                    </fieldset>
                    {enabledLocales.map((locale) => (
                        <div key={locale.code} className="grid gap-1">
                            <Label htmlFor={`handover-${locale.code}`}>
                                {t('Message when staff take over (:language)', {
                                    language: locale.label,
                                })}
                            </Label>
                            <Input
                                id={`handover-${locale.code}`}
                                placeholder={t(
                                    'Thank you for your message. A member of our team will reply to you shortly.',
                                )}
                                value={
                                    form.data.handover_messages[locale.code] ??
                                    ''
                                }
                                onChange={(e) =>
                                    form.setData('handover_messages', {
                                        ...form.data.handover_messages,
                                        [locale.code]: e.target.value,
                                    })
                                }
                            />
                        </div>
                    ))}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-1">
                            <Label htmlFor="daily_budget">
                                {t('Daily AI spending limit (US$)')}
                            </Label>
                            <Input
                                id="daily_budget"
                                type="number"
                                step="0.5"
                                min={0}
                                value={form.data.daily_budget}
                                onChange={(e) =>
                                    form.setData(
                                        'daily_budget',
                                        Number(e.target.value),
                                    )
                                }
                            />
                            <InputError message={errors.daily_budget} />
                        </div>
                        <label className="flex items-center gap-2 self-end text-sm">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={form.data.kill_switch}
                                onChange={(e) =>
                                    form.setData(
                                        'kill_switch',
                                        e.target.checked,
                                    )
                                }
                            />
                            <span
                                className={
                                    form.data.kill_switch
                                        ? 'font-medium text-destructive'
                                        : ''
                                }
                            >
                                {t(
                                    'Switch the assistant off now (staff answer everything)',
                                )}
                            </span>
                        </label>
                    </div>
                    <div>
                        <Button disabled={form.processing}>{t('Save')}</Button>
                    </div>
                </form>
            </div>
        </>
    );
}

AssistantSettings.layout = {
    breadcrumbs: [
        { title: 'AI assistant', href: AssistantSettingsController.show() },
    ],
};
