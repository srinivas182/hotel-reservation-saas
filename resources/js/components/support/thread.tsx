import { t } from '@/lib/i18n';

export type ThreadMessage = {
    id: number;
    side: string;
    internal: boolean;
    body: string;
    author: string | null;
    at: string | null;
    ai_drafted: boolean;
    attachments: string[];
};

export function Thread({ messages }: { messages: ThreadMessage[] }) {
    return (
        <ol className="flex flex-col gap-3">
            {messages.map((m) => (
                <li
                    key={m.id}
                    className={`rounded-xl border p-3 ${m.internal ? 'border-dashed bg-amber-50 dark:bg-amber-950/30' : m.side === 'support' ? 'bg-muted/50' : ''}`}
                >
                    <p className="mb-1 text-xs text-muted-foreground">
                        {m.author ?? (m.side === 'support' ? t('Support') : '')}{' '}
                        · {m.at}
                        {m.internal && ` · ${t('Internal note')}`}
                        {m.ai_drafted && ` · ${t('AI draft, reviewed')}`}
                    </p>
                    <p className="whitespace-pre-wrap">{m.body}</p>
                    {m.attachments.length > 0 && (
                        <div className="mt-2 flex flex-wrap gap-2">
                            {m.attachments.map((a) => (
                                <a
                                    key={a}
                                    href={a}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <img
                                        src={a}
                                        alt={t('Screenshot')}
                                        className="h-24 rounded border object-cover"
                                    />
                                </a>
                            ))}
                        </div>
                    )}
                </li>
            ))}
        </ol>
    );
}
