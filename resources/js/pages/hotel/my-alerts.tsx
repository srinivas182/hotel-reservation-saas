import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AlertSettingsController from '@/actions/App/Domains/StaffAlerts/Http/Controllers/AlertSettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

type Props = {
    settings: {
        alert_phone: string;
        alerts: string[];
        summary_time: string;
        summary_channels: string[];
    };
    kinds: { value: string; label: string }[];
    isOwner: boolean;
    team: { name: string | null; phone: string | null; alerts: string[] }[];
    recent: {
        kind: string;
        how: string;
        reason: string | null;
        at: string | null;
    }[];
};

export default function MyAlerts({
    settings,
    kinds,
    isOwner,
    team,
    recent,
}: Props) {
    const form = useForm(settings);
    const errors = form.errors as Record<string, string | undefined>;
    const toggle = (key: 'alerts' | 'summary_channels', value: string) =>
        form.setData(
            key,
            form.data[key].includes(value)
                ? form.data[key].filter((v) => v !== value)
                : [...form.data[key], value],
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(AlertSettingsController.update.url(), {
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title={t('My alerts')} />
            <div className="flex max-w-3xl flex-col gap-6 p-4">
                <Heading
                    title={t('My alerts')}
                    description={t(
                        'Get WhatsApp alerts on your own phone, sent from the hotel’s number. Meta charges the hotel for each alert outside a conversation.',
                    )}
                />

                <form
                    onSubmit={submit}
                    className="flex flex-col gap-4 rounded-xl border p-4"
                >
                    <div className="grid max-w-sm gap-1">
                        <Label htmlFor="alert_phone">
                            {t('My WhatsApp number for this hotel')}
                        </Label>
                        <Input
                            id="alert_phone"
                            type="tel"
                            placeholder="+2250700000000"
                            value={form.data.alert_phone}
                            onChange={(e) =>
                                form.setData('alert_phone', e.target.value)
                            }
                        />
                        <InputError message={errors.alert_phone} />
                    </div>
                    <fieldset className="flex flex-col gap-2 text-sm">
                        <legend className="mb-1 font-medium">
                            {t('Alert me when')}
                        </legend>
                        {kinds.map((kind) => (
                            <label
                                key={kind.value}
                                className="flex items-center gap-2"
                            >
                                <input
                                    type="checkbox"
                                    className="size-4"
                                    checked={form.data.alerts.includes(
                                        kind.value,
                                    )}
                                    onChange={() =>
                                        toggle('alerts', kind.value)
                                    }
                                />
                                {kind.label}
                            </label>
                        ))}
                    </fieldset>

                    {isOwner && (
                        <fieldset className="flex flex-col gap-2 border-t pt-4 text-sm">
                            <legend className="mb-1 font-medium">
                                {t('Daily summary (owners)')}
                            </legend>
                            <div className="flex flex-wrap items-center gap-4">
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        className="size-4"
                                        checked={form.data.summary_channels.includes(
                                            'whatsapp',
                                        )}
                                        onChange={() =>
                                            toggle(
                                                'summary_channels',
                                                'whatsapp',
                                            )
                                        }
                                    />
                                    WhatsApp
                                </label>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        className="size-4"
                                        checked={form.data.summary_channels.includes(
                                            'email',
                                        )}
                                        onChange={() =>
                                            toggle('summary_channels', 'email')
                                        }
                                    />
                                    {t('Email')}
                                </label>
                                <Label htmlFor="summary_time">{t('at')}</Label>
                                <Input
                                    id="summary_time"
                                    type="time"
                                    className="w-32"
                                    value={form.data.summary_time}
                                    onChange={(e) =>
                                        form.setData(
                                            'summary_time',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                        </fieldset>
                    )}

                    <div className="flex flex-wrap gap-2">
                        <Button disabled={form.processing}>{t('Save')}</Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    AlertSettingsController.test.url(),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {t('Send me a test alert')}
                        </Button>
                    </div>
                </form>

                {recent.length > 0 && (
                    <section className="flex flex-col gap-2 text-sm">
                        <Heading
                            variant="small"
                            title={t('My recent alerts')}
                        />
                        <ul className="divide-y rounded-xl border">
                            {recent.map((alert, index) => (
                                <li
                                    key={index}
                                    className="flex justify-between gap-4 px-4 py-2"
                                >
                                    <span>
                                        {alert.kind} · {alert.how}
                                        {alert.reason && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                ({alert.reason})
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {alert.at}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {isOwner && team.length > 0 && (
                    <section className="flex flex-col gap-2 text-sm">
                        <Heading variant="small" title={t('Team alerts')} />
                        <ul className="divide-y rounded-xl border">
                            {team.map((member, index) => (
                                <li key={index} className="px-4 py-2">
                                    <div className="font-medium">
                                        {member.name}
                                    </div>
                                    <div className="text-muted-foreground">
                                        {member.phone ?? t('No phone')} ·{' '}
                                        {member.alerts.length > 0
                                            ? member.alerts.join(', ')
                                            : t('No alerts')}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

MyAlerts.layout = {
    breadcrumbs: [{ title: 'My alerts', href: AlertSettingsController.show() }],
};
