import { Head, useForm } from '@inertiajs/react';
import HotelSupportController from '@/actions/App/Domains/Support/Http/Controllers/HotelSupportController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Thread } from '@/components/support/thread';
import type { ThreadMessage } from '@/components/support/thread';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { statusLabel } from './support';

type Props = {
    ticket: {
        id: number;
        subject: string;
        category: string;
        status: string;
        rating: string | null;
        rating_comment: string | null;
        messages: ThreadMessage[];
    };
};

export default function SupportTicket({ ticket }: Props) {
    const reply = useForm({ body: '' });
    const rate = useForm({ rating: '', comment: '' });

    return (
        <>
            <Head title={`#${ticket.id}`} />
            <div className="flex max-w-3xl flex-col gap-4 p-4 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                    <Heading
                        title={`#${ticket.id} ${ticket.subject}`}
                        description={ticket.category}
                    />
                    <Badge variant="outline">
                        {statusLabel(ticket.status)}
                    </Badge>
                </div>
                <Thread messages={ticket.messages} />

                {ticket.status === 'solved' &&
                    (ticket.rating ? (
                        <p className="rounded-lg border px-3 py-2">
                            {t('Thank you for your rating.')}
                        </p>
                    ) : (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                rate.post(
                                    HotelSupportController.rate.url(ticket.id),
                                    { preserveScroll: true },
                                );
                            }}
                            className="flex flex-col gap-2 rounded-xl border p-3"
                        >
                            <p className="font-medium">{t('Did we help?')}</p>
                            <div className="flex gap-2">
                                {(['good', 'bad'] as const).map((r) => (
                                    <Button
                                        key={r}
                                        type="button"
                                        variant={
                                            rate.data.rating === r
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() =>
                                            rate.setData('rating', r)
                                        }
                                        aria-pressed={rate.data.rating === r}
                                    >
                                        {r === 'good'
                                            ? `👍 ${t('Yes')}`
                                            : `👎 ${t('No')}`}
                                    </Button>
                                ))}
                            </div>
                            <textarea
                                aria-label={t('A comment (optional)')}
                                placeholder={t('A comment (optional)')}
                                className="min-h-14 rounded-md border border-input bg-transparent px-3 py-2"
                                value={rate.data.comment}
                                onChange={(e) =>
                                    rate.setData('comment', e.target.value)
                                }
                            />
                            <div>
                                <Button
                                    size="sm"
                                    disabled={
                                        rate.processing ||
                                        rate.data.rating === ''
                                    }
                                >
                                    {t('Send')}
                                </Button>
                            </div>
                        </form>
                    ))}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        reply.post(
                            HotelSupportController.reply.url(ticket.id),
                            {
                                preserveScroll: true,
                                onSuccess: () => reply.reset(),
                            },
                        );
                    }}
                    className="flex flex-col gap-2"
                >
                    <label className="grid gap-1">
                        <span className="font-medium">
                            {ticket.status === 'solved'
                                ? t(
                                      'Still a problem? Reply to reopen the request',
                                  )
                                : t('Your reply')}
                        </span>
                        <textarea
                            className="min-h-24 rounded-md border border-input bg-transparent px-3 py-2"
                            value={reply.data.body}
                            onChange={(e) =>
                                reply.setData('body', e.target.value)
                            }
                        />
                    </label>
                    <InputError message={reply.errors.body} />
                    <div>
                        <Button
                            size="sm"
                            disabled={
                                reply.processing ||
                                reply.data.body.trim() === ''
                            }
                        >
                            {t('Send')}
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

SupportTicket.layout = {
    breadcrumbs: [{ title: 'Support', href: HotelSupportController.index() }],
};
