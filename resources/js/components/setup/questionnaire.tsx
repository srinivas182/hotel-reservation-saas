import { useForm } from '@inertiajs/react';
import SetupController from '@/actions/App/Domains/Onboarding/Http/Controllers/SetupController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

export type QuestionnaireProps = {
    questions: Record<string, string>;
    answers: Record<string, string>;
};

export function Questionnaire({ questions, answers }: QuestionnaireProps) {
    const form = useForm({
        answers: {
            ...Object.fromEntries(Object.keys(questions).map((k) => [k, ''])),
            ...answers,
        } as Record<string, string>,
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(SetupController.draftPack.url());
            }}
            className="flex flex-col gap-3"
        >
            <p className="text-xs text-muted-foreground">
                {t(
                    'Answer what you can. The assistant drafts your information pack in French and English from your answers; you check it and publish it yourself.',
                )}
            </p>
            {Object.entries(questions).map(([key, question]) => (
                <label key={key} className="grid gap-1">
                    <span className="font-medium">{question}</span>
                    <textarea
                        className="min-h-14 rounded-md border border-input bg-transparent px-3 py-2"
                        value={form.data.answers[key] ?? ''}
                        onChange={(e) =>
                            form.setData('answers', {
                                ...form.data.answers,
                                [key]: e.target.value,
                            })
                        }
                    />
                </label>
            ))}
            <InputError message={errors.answers} />
            <div>
                <Button disabled={form.processing}>
                    {form.processing
                        ? t('Drafting…')
                        : t('Draft my information pack')}
                </Button>
            </div>
        </form>
    );
}
