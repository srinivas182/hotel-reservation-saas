import { Head, router, useForm } from '@inertiajs/react';
import TemplateController from '@/actions/App/Domains/WhatsApp/Http/Controllers/TemplateController';
import WhatsAppConnectionController from '@/actions/App/Domains/WhatsApp/Http/Controllers/WhatsAppConnectionController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

type Template = {
    id: string;
    name: string;
    language: string;
    language_label: string;
    category: string;
    body: string;
    status: string;
    status_label: string;
    rejection_reason: string | null;
};

type Props = {
    templates: Template[];
    starters: { key: string; label: string; languages: string[] }[];
    locales: Record<string, string>;
};

const variant = (status: string) =>
    status === 'approved'
        ? 'default'
        : status === 'rejected' || status === 'paused' || status === 'disabled'
          ? 'destructive'
          : 'secondary';

export default function WhatsAppTemplates({
    templates,
    starters,
    locales,
}: Props) {
    const form = useForm({
        starter: starters[0]?.key ?? '',
        language: starters[0]?.languages[0] ?? '',
    });
    const starter = starters.find((s) => s.key === form.data.starter);

    return (
        <>
            <Head title={t('WhatsApp templates')} />
            <div className="flex max-w-4xl flex-col gap-6 p-4">
                <Heading
                    title={t('WhatsApp templates')}
                    description={t(
                        'Pre-approved messages Meta allows the hotel to send more than 24 hours after a guest’s last message.',
                    )}
                />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(TemplateController.store.url(), {
                            preserveScroll: true,
                        });
                    }}
                    className="flex flex-wrap items-end gap-3 rounded-xl border p-4"
                >
                    <div className="grid gap-1">
                        <Label htmlFor="starter">{t('Template')}</Label>
                        <select
                            id="starter"
                            className="flex h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                            value={form.data.starter}
                            onChange={(e) =>
                                form.setData('starter', e.target.value)
                            }
                        >
                            {starters.map((s) => (
                                <option key={s.key} value={s.key}>
                                    {s.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="language">{t('Language')}</Label>
                        <select
                            id="language"
                            className="flex h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                            value={form.data.language}
                            onChange={(e) =>
                                form.setData('language', e.target.value)
                            }
                        >
                            {(starter?.languages ?? []).map((code) => (
                                <option key={code} value={code}>
                                    {locales[code] ?? code}
                                </option>
                            ))}
                        </select>
                    </div>
                    <Button disabled={form.processing || !form.data.language}>
                        {t('Add and submit to Meta')}
                    </Button>
                    <InputError message={form.errors.language} />
                </form>

                <ul className="flex flex-col gap-2">
                    {templates.length === 0 && (
                        <li className="text-sm text-muted-foreground">
                            {t('No templates yet.')}
                        </li>
                    )}
                    {templates.map((template) => (
                        <li
                            key={template.id}
                            className="flex flex-col gap-2 rounded-xl border p-4 text-sm"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">
                                    {template.name}
                                </span>
                                <span className="text-muted-foreground">
                                    {template.language_label} ·{' '}
                                    {template.category}
                                </span>
                                <Badge variant={variant(template.status)}>
                                    {template.status_label}
                                </Badge>
                            </div>
                            <p className="whitespace-pre-wrap text-muted-foreground">
                                {template.body}
                            </p>
                            {template.rejection_reason && (
                                <p className="text-destructive">
                                    {template.rejection_reason}
                                </p>
                            )}
                            <div className="flex gap-2">
                                {['draft', 'rejected', 'paused'].includes(
                                    template.status,
                                ) && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                TemplateController.submit.url(
                                                    template.id,
                                                ),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t('Submit to Meta')}
                                    </Button>
                                )}
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="text-destructive"
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                t('Delete :name?', {
                                                    name: template.name,
                                                }),
                                            )
                                        ) {
                                            router.delete(
                                                TemplateController.destroy.url(
                                                    template.id,
                                                ),
                                                { preserveScroll: true },
                                            );
                                        }
                                    }}
                                >
                                    {t('Delete')}
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

WhatsAppTemplates.layout = {
    breadcrumbs: [
        { title: 'WhatsApp', href: WhatsAppConnectionController.show() },
        { title: 'Templates', href: TemplateController.index() },
    ],
};
