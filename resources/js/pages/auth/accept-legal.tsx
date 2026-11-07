import { Head, useForm } from '@inertiajs/react';
import LegalController from '@/actions/App/Domains/Onboarding/Http/Controllers/LegalController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

type Props = {
    documents: {
        id: number;
        kind: string;
        label: string;
        version: number;
        url: string;
    }[];
};

export default function AcceptLegal({ documents }: Props) {
    const form = useForm({ accept: false });

    return (
        <>
            <Head title={t('Updated terms')} />
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(LegalController.accept.url());
                }}
                className="grid gap-4"
            >
                <ul className="grid gap-1 text-sm">
                    {documents.map((d) => (
                        <li key={d.id}>
                            <a
                                href={d.url}
                                target="_blank"
                                rel="noreferrer"
                                className="underline"
                            >
                                {d.label}
                            </a>{' '}
                            <span className="text-muted-foreground">
                                {t('version :version', { version: d.version })}
                            </span>
                        </li>
                    ))}
                </ul>
                <label className="flex items-start gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="mt-1"
                        checked={form.data.accept}
                        onChange={(e) =>
                            form.setData('accept', e.target.checked)
                        }
                    />
                    {t('I have read and accept these texts.')}
                </label>
                <InputError message={form.errors.accept} />
                <Button disabled={form.processing}>{t('Continue')}</Button>
            </form>
        </>
    );
}

AcceptLegal.layout = {
    title: 'Updated terms',
    description: 'Please read and accept the current versions to continue.',
};
