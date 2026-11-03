import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import AssistantSettingsController from '@/actions/App/Domains/Assistant/Http/Controllers/AssistantSettingsController';
import PracticeController from '@/actions/App/Domains/Assistant/Http/Controllers/PracticeController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Turn = { role: 'guest' | 'hotel'; text: string };
type Result = {
    reply: string;
    language: string;
    intent: string;
    handover: boolean;
    handoverReason: string | null;
    confidence: number;
    tools: {
        tool: string;
        arguments: Record<string, unknown>;
        result: string;
    }[];
    guardrails: { check: string; passed: boolean; detail: string | null }[];
    model: string | null;
    latencyMs: number;
    cost: number;
    attempts: number;
};

export default function Practice() {
    const [turns, setTurns] = useState<Turn[]>([]);
    const [draft, setDraft] = useState('');
    const [result, setResult] = useState<Result | null>(null);
    const [busy, setBusy] = useState(false);

    const send = (event: FormEvent) => {
        event.preventDefault();
        const text = draft.trim();

        if (!text) {
            return;
        }

        const next: Turn[] = [...turns, { role: 'guest', text }];
        setTurns(next);
        setDraft('');
        setBusy(true);

        router.post(
            PracticeController.run.url(),
            { turns: next },
            {
                preserveScroll: true,
                preserveState: true,
                onFlash: (flash) => {
                    const practice = (flash as { practice?: Result }).practice;

                    if (practice) {
                        setResult(practice);
                        setTurns((current) => [
                            ...current,
                            { role: 'hotel', text: practice.reply },
                        ]);
                    }
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <>
            <Head title={t('Practice')} />
            <div className="grid max-w-6xl gap-6 p-4 lg:grid-cols-[1fr_24rem]">
                <section className="flex flex-col gap-4">
                    <div className="flex items-start justify-between gap-3">
                        <Heading
                            title={t('Practice')}
                            description={t(
                                'Write as a guest would. The assistant uses the published information pack and live rates. Nothing is sent to anyone.',
                            )}
                        />
                        <Button
                            variant="outline"
                            onClick={() => {
                                setTurns([]);
                                setResult(null);
                            }}
                        >
                            {t('Start again')}
                        </Button>
                    </div>
                    <div className="flex min-h-64 flex-col gap-2 rounded-xl border p-4">
                        {turns.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'For example: Bonjour, vous avez une chambre pour 2 personnes ce week-end ? C’est combien ?',
                                )}
                            </p>
                        )}
                        {turns.map((turn, index) => (
                            <div
                                key={index}
                                className={cn(
                                    'flex',
                                    turn.role === 'hotel'
                                        ? 'justify-end'
                                        : 'justify-start',
                                )}
                            >
                                <div
                                    className={cn(
                                        'max-w-[80%] rounded-2xl px-3 py-2 text-sm whitespace-pre-wrap',
                                        turn.role === 'hotel'
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-muted',
                                    )}
                                >
                                    {turn.text}
                                </div>
                            </div>
                        ))}
                        {busy && (
                            <p className="text-sm text-muted-foreground">
                                {t('The assistant is thinking…')}
                            </p>
                        )}
                    </div>
                    <form onSubmit={send} className="flex gap-2">
                        <textarea
                            aria-label={t('Guest message')}
                            className="min-h-10 flex-1 rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                            rows={2}
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                        />
                        <Button disabled={busy || !draft.trim()}>
                            {t('Send as guest')}
                        </Button>
                    </form>
                </section>

                <aside className="flex flex-col gap-3 text-sm">
                    <Heading
                        variant="small"
                        title={t('How the assistant decided')}
                    />
                    {!result && (
                        <p className="text-muted-foreground">
                            {t('Details appear after each reply.')}
                        </p>
                    )}
                    {result && (
                        <>
                            <div className="flex flex-wrap gap-2">
                                <Badge
                                    variant={
                                        result.handover
                                            ? 'destructive'
                                            : 'default'
                                    }
                                >
                                    {result.handover
                                        ? t('Passed to staff')
                                        : t('Answered')}
                                </Badge>
                                <Badge variant="secondary">
                                    {result.language}
                                </Badge>
                                <Badge variant="outline">{result.intent}</Badge>
                            </div>
                            {result.handoverReason && (
                                <p>
                                    {t('Reason: :reason', {
                                        reason: result.handoverReason,
                                    })}
                                </p>
                            )}
                            <p className="text-muted-foreground">
                                {t(
                                    'Confidence :value · :ms ms · US$ :cost · :attempts attempt(s)',
                                    {
                                        value: result.confidence.toFixed(2),
                                        ms: result.latencyMs,
                                        cost: result.cost,
                                        attempts: result.attempts,
                                    },
                                )}
                                {result.model && ` · ${result.model}`}
                            </p>
                            <div>
                                <div className="font-medium">{t('Checks')}</div>
                                <ul>
                                    {result.guardrails.map((g) => (
                                        <li
                                            key={g.check}
                                            className={
                                                g.passed
                                                    ? 'text-muted-foreground'
                                                    : 'text-destructive'
                                            }
                                        >
                                            {g.passed ? '✓' : '✗'} {g.check}
                                            {g.detail && `: ${g.detail}`}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                            <div>
                                <div className="font-medium">
                                    {t('Tools used')}
                                </div>
                                {result.tools.length === 0 && (
                                    <p className="text-muted-foreground">
                                        {t('None')}
                                    </p>
                                )}
                                {result.tools.map((tool, index) => (
                                    <details
                                        key={index}
                                        className="rounded-md border px-2 py-1"
                                    >
                                        <summary>
                                            {tool.tool}{' '}
                                            <code className="text-xs">
                                                {JSON.stringify(tool.arguments)}
                                            </code>
                                        </summary>
                                        <pre className="overflow-x-auto text-xs whitespace-pre-wrap">
                                            {tool.result}
                                        </pre>
                                    </details>
                                ))}
                            </div>
                        </>
                    )}
                </aside>
            </div>
        </>
    );
}

Practice.layout = {
    breadcrumbs: [
        { title: 'AI assistant', href: AssistantSettingsController.show() },
        { title: 'Practice', href: PracticeController.show() },
    ],
};
