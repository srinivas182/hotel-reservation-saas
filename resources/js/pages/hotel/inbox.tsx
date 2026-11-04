import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import { Check, CheckCheck, Clock, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import BookingController from '@/actions/App/Domains/Bookings/Http/Controllers/BookingController';
import InboxController from '@/actions/App/Domains/Conversations/Http/Controllers/InboxController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { RequestCard } from '@/components/booking-requests/request-card';
import type { PendingRequest } from '@/components/booking-requests/request-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type ListItem = {
    id: string;
    name: string;
    phone: string;
    unread: number;
    mode: string;
    needs_attention: boolean;
    window_open: boolean;
    last_at: string | null;
    preview: string | null;
};

type ThreadMessage = {
    id: string;
    direction: 'in' | 'out';
    type: string;
    body: string | null;
    template_name: string | null;
    media_url: string | null;
    media_error: string | null;
    mime_type: string | null;
    filename: string | null;
    location: { lat: number; lng: number } | null;
    status: string;
    error: string | null;
    transcript: string | null;
    transcript_error: string | null;
    source: string;
    by: string | null;
    at: string;
};

type Thread = {
    id: string;
    name: string;
    phone: string;
    mode: string;
    mode_label: string;
    needs_attention: boolean;
    handover_reason: string | null;
    window_open: boolean;
    window_closes_at: string | null;
    bookings: {
        id: string;
        reference: string;
        status_label: string;
        check_in: string;
        check_out: string;
    }[];
    messages: ThreadMessage[];
};

type Props = {
    filters: { search?: string; filter?: string };
    connected: boolean;
    conversations: ListItem[];
    conversation: Thread | null;
    canReply: boolean;
    templates: TemplateOption[];
    requests: PendingRequest[];
    requestCategories: { id: string; name: string }[];
    canOverridePrice: boolean;
};

type TemplateOption = {
    id: string;
    name: string;
    language: string;
    body: string;
    placeholders: number;
    examples: string[];
};

function TemplatePicker({
    thread,
    templates,
}: {
    thread: Thread;
    templates: TemplateOption[];
}) {
    const form = useForm({
        template_id: templates[0]?.id ?? '',
        values: [] as string[],
    });
    const template = templates.find(
        (item) => item.id === form.data.template_id,
    );

    if (templates.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t(
                    'No approved templates yet. The owner can add them under WhatsApp → Message templates.',
                )}
            </p>
        );
    }

    const preview = template
        ? template.body.replace(
              /\{\{(\d+)\}\}/g,
              (match, n) => form.data.values[Number(n) - 1] || match,
          )
        : '';

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(InboxController.sendTemplate.url(thread.id), {
                    preserveScroll: true,
                    onSuccess: () => form.setData('values', []),
                });
            }}
            className="flex flex-col gap-2"
        >
            <select
                aria-label={t('Template')}
                className="flex h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                value={form.data.template_id}
                onChange={(e) =>
                    form.setData({ template_id: e.target.value, values: [] })
                }
            >
                {templates.map((item) => (
                    <option key={item.id} value={item.id}>
                        {item.name} ({item.language})
                    </option>
                ))}
            </select>
            {template &&
                Array.from({ length: template.placeholders }, (_, index) => (
                    <Input
                        key={index}
                        aria-label={t('Value :n', { n: index + 1 })}
                        placeholder={
                            template.examples[index] ?? `{{${index + 1}}}`
                        }
                        value={form.data.values[index] ?? ''}
                        onChange={(e) => {
                            const values = [...form.data.values];
                            values[index] = e.target.value;
                            form.setData('values', values);
                        }}
                    />
                ))}
            <p className="rounded-md bg-muted px-3 py-2 text-sm whitespace-pre-wrap">
                {preview}
            </p>
            <InputError message={form.errors.template_id} />
            <Button className="w-fit" disabled={form.processing}>
                {t('Send template')}
            </Button>
        </form>
    );
}

function StatusIcon({ status }: { status: string }) {
    if (status === 'read') {
        return (
            <CheckCheck
                className="size-3.5 text-sky-500"
                aria-label={t('Read')}
            />
        );
    }

    if (status === 'delivered') {
        return <CheckCheck className="size-3.5" aria-label={t('Delivered')} />;
    }

    if (status === 'sent' || status === 'accepted') {
        return <Check className="size-3.5" aria-label={t('Sent')} />;
    }

    if (status === 'failed') {
        return (
            <TriangleAlert
                className="size-3.5 text-destructive"
                aria-label={t('Not delivered')}
            />
        );
    }

    return <Clock className="size-3.5" aria-label={t('Sending')} />;
}

function Bubble({ message }: { message: ThreadMessage }) {
    const out = message.direction === 'out';

    return (
        <div className={cn('flex', out ? 'justify-end' : 'justify-start')}>
            <div
                className={cn(
                    'max-w-[80%] rounded-2xl px-3 py-2 text-sm',
                    out ? 'bg-primary text-primary-foreground' : 'bg-muted',
                )}
            >
                {message.type === 'audio' && message.media_url && (
                    <audio
                        controls
                        src={message.media_url}
                        className="max-w-full"
                    />
                )}
                {message.type === 'audio' && message.transcript && (
                    <p className="mt-1 text-xs italic opacity-90">
                        {t('Transcript:')} {message.transcript}
                    </p>
                )}
                {message.type === 'audio' && message.transcript_error && (
                    <p className="mt-1 text-xs italic opacity-80">
                        {message.transcript_error}
                    </p>
                )}
                {message.type === 'image' && message.media_url && (
                    <a
                        href={message.media_url}
                        target="_blank"
                        rel="noreferrer"
                    >
                        <img
                            src={message.media_url}
                            alt={message.body ?? t('Photo')}
                            className="max-h-64 rounded-lg"
                        />
                    </a>
                )}
                {['document', 'video'].includes(message.type) &&
                    message.media_url && (
                        <a
                            href={message.media_url}
                            target="_blank"
                            rel="noreferrer"
                            className="underline"
                        >
                            {message.filename ?? t('Open file')}
                        </a>
                    )}
                {message.media_error && (
                    <p className="italic opacity-80">{message.media_error}</p>
                )}
                {message.location && (
                    <a
                        href={`https://www.openstreetmap.org/?mlat=${message.location.lat}&mlon=${message.location.lng}`}
                        target="_blank"
                        rel="noreferrer"
                        className="underline"
                    >
                        {t('Location')}
                    </a>
                )}
                {message.type === 'template' && (
                    <p className="text-xs opacity-80">
                        {t('Template: :name', {
                            name: message.template_name ?? '',
                        })}
                    </p>
                )}
                {message.body && (
                    <p className="whitespace-pre-wrap">{message.body}</p>
                )}
                {!message.body &&
                    !message.media_url &&
                    !message.media_error &&
                    !message.location &&
                    message.type !== 'template' && (
                        <p className="italic opacity-80">
                            {t('Message type not shown here (:type)', {
                                type: message.type,
                            })}
                        </p>
                    )}
                <div
                    className={cn(
                        'mt-1 flex items-center justify-end gap-1 text-[11px]',
                        out
                            ? 'text-primary-foreground/80'
                            : 'text-muted-foreground',
                    )}
                >
                    {out && message.source === 'business_app' && (
                        <span>{t('from the WhatsApp app')} ·</span>
                    )}
                    {out && message.source === 'assistant' && (
                        <span>{t('Assistant')} ·</span>
                    )}
                    {out && message.by && <span>{message.by} ·</span>}
                    <span>{message.at}</span>
                    {out && <StatusIcon status={message.status} />}
                </div>
                {message.error && (
                    <p className="mt-1 text-xs text-destructive-foreground">
                        {message.error}
                    </p>
                )}
            </div>
        </div>
    );
}

function Reply({
    thread,
    canReply,
    connected,
    templates,
}: {
    thread: Thread;
    canReply: boolean;
    connected: boolean;
    templates: TemplateOption[];
}) {
    const form = useForm({ body: '' });

    if (!canReply) {
        return null;
    }

    if (!connected) {
        return (
            <p className="border-t p-3 text-sm text-muted-foreground">
                {t('WhatsApp is not connected. Ask the owner to reconnect it.')}
            </p>
        );
    }

    if (!thread.window_open) {
        return (
            <div className="flex flex-col gap-2 border-t p-3">
                <p className="text-sm text-muted-foreground">
                    {t(
                        'More than 24 hours have passed since the guest’s last message. Only approved templates can be sent.',
                    )}
                </p>
                <TemplatePicker thread={thread} templates={templates} />
            </div>
        );
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(InboxController.reply.url(thread.id), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-1 border-t p-3">
            <div className="flex gap-2">
                <textarea
                    aria-label={t('Reply')}
                    className="min-h-10 flex-1 resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs"
                    rows={2}
                    maxLength={4096}
                    value={form.data.body}
                    onChange={(e) => form.setData('body', e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            submit(e);
                        }
                    }}
                />
                <Button
                    disabled={form.processing || form.data.body.trim() === ''}
                >
                    {t('Send')}
                </Button>
            </div>
            <InputError message={form.errors.body} />
            {thread.window_closes_at && (
                <p className="text-xs text-muted-foreground">
                    {t('Free replies possible until :time.', {
                        time: thread.window_closes_at,
                    })}
                </p>
            )}
        </form>
    );
}

function ModeControl({
    conversation,
    canReply,
}: {
    conversation: Thread;
    canReply: boolean;
}) {
    const setMode = (mode: string) =>
        router.put(
            InboxController.mode.url(conversation.id),
            { mode },
            { preserveScroll: true },
        );

    return (
        <div className="flex flex-wrap items-center gap-2 text-xs">
            <Badge
                variant={
                    conversation.mode === 'assistant' ? 'default' : 'secondary'
                }
            >
                {conversation.mode_label}
            </Badge>
            {conversation.needs_attention && conversation.handover_reason && (
                <span className="text-destructive">
                    {t('Passed to staff: :reason', {
                        reason: conversation.handover_reason,
                    })}
                </span>
            )}
            {canReply && conversation.mode === 'assistant' && (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setMode('staff')}
                >
                    {t('Take over')}
                </Button>
            )}
            {canReply && conversation.mode !== 'assistant' && (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setMode('assistant')}
                >
                    {t('Hand back to the assistant')}
                </Button>
            )}
        </div>
    );
}

export default function Inbox({
    filters,
    connected,
    conversations,
    conversation,
    canReply,
    templates,
    requests,
    requestCategories,
    canOverridePrice,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const bottom = useRef<HTMLDivElement>(null);
    usePoll(10000, {
        only: ['conversations', 'conversation', 'inboxUnread', 'requests'],
    });

    useEffect(() => {
        bottom.current?.scrollIntoView({ block: 'end' });
    }, [conversation?.id, conversation?.messages.length]);

    const query = (extra: Record<string, string>) => {
        const values = {
            ...(search ? { search } : {}),
            ...(filters.filter ? { filter: filters.filter } : {}),
            ...extra,
        };

        return Object.fromEntries(
            Object.entries(values).filter(([, value]) => value !== ''),
        );
    };

    return (
        <>
            <Head title={t('Inbox')} />
            <div className="grid h-[calc(100vh-4rem)] min-h-[32rem] grid-cols-1 md:grid-cols-[20rem_1fr]">
                <aside
                    className={cn(
                        'flex flex-col border-r',
                        conversation && 'hidden md:flex',
                    )}
                >
                    <div className="flex flex-col gap-2 border-b p-3">
                        <Heading title={t('Inbox')} />
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.get(
                                    InboxController.index.url({
                                        query: query({}),
                                    }),
                                    {},
                                    { preserveState: true },
                                );
                            }}
                        >
                            <Input
                                type="search"
                                placeholder={t('Name or phone')}
                                aria-label={t('Search conversations')}
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </form>
                        <div className="flex gap-1 text-xs">
                            {[
                                ['', t('All')],
                                ['unread', t('Unread')],
                                ['closing', t('Window closing')],
                                ['attention', t('Needs attention')],
                            ].map(([value, label]) => (
                                <Button
                                    key={value}
                                    size="sm"
                                    variant={
                                        (filters.filter ?? '') === value
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() =>
                                        router.get(
                                            InboxController.index.url({
                                                query: query({ filter: value }),
                                            }),
                                            {},
                                            { preserveState: true },
                                        )
                                    }
                                >
                                    {label}
                                </Button>
                            ))}
                        </div>
                    </div>
                    <ul className="flex-1 overflow-y-auto">
                        {conversations.length === 0 && (
                            <li className="p-6 text-center text-sm text-muted-foreground">
                                {t('No conversations yet.')}
                            </li>
                        )}
                        {conversations.map((item) => (
                            <li key={item.id}>
                                <Link
                                    href={InboxController.show.url(item.id)}
                                    preserveScroll
                                    className={cn(
                                        'flex flex-col gap-0.5 border-b px-3 py-2 text-sm hover:bg-muted/50',
                                        conversation?.id === item.id &&
                                            'bg-muted',
                                    )}
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span
                                            className={cn(
                                                'truncate',
                                                item.unread > 0 &&
                                                    'font-semibold',
                                            )}
                                        >
                                            {item.name}
                                        </span>
                                        {item.needs_attention && (
                                            <Badge variant="destructive">
                                                {t('Needs attention')}
                                            </Badge>
                                        )}
                                        {item.unread > 0 && (
                                            <Badge>{item.unread}</Badge>
                                        )}
                                    </div>
                                    <span className="truncate text-xs text-muted-foreground">
                                        {item.preview ?? item.phone}
                                    </span>
                                    <span className="text-[11px] text-muted-foreground">
                                        {item.last_at}
                                        {!item.window_open &&
                                            ` · ${t('templates only')}`}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </aside>

                <section
                    className={cn(
                        'flex min-h-0 flex-col',
                        !conversation && 'hidden md:flex',
                    )}
                >
                    {!conversation ? (
                        <div className="m-auto p-6 text-center text-sm text-muted-foreground">
                            {connected
                                ? t('Choose a conversation.')
                                : t('WhatsApp is not connected yet.')}
                        </div>
                    ) : (
                        <>
                            <header className="flex flex-wrap items-center gap-3 border-b p-3">
                                <Link
                                    href={InboxController.index.url()}
                                    className="text-sm text-muted-foreground md:hidden"
                                >
                                    ← {t('Back')}
                                </Link>
                                <div className="min-w-0 flex-1">
                                    <div className="font-medium">
                                        {conversation.name}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {conversation.phone}
                                    </div>
                                </div>
                                <ModeControl
                                    conversation={conversation}
                                    canReply={canReply}
                                />
                                <Badge
                                    variant={
                                        conversation.window_open
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {conversation.window_open
                                        ? t('Reply window open')
                                        : t('Templates only')}
                                </Badge>
                                {conversation.bookings.map((booking) => (
                                    <Link
                                        key={booking.id}
                                        href={BookingController.show(
                                            booking.id,
                                        )}
                                        className="text-xs underline"
                                    >
                                        {booking.reference} ·{' '}
                                        {booking.status_label}
                                    </Link>
                                ))}
                            </header>
                            <div className="flex flex-1 flex-col gap-2 overflow-y-auto p-4">
                                {conversation.messages.map((message) => (
                                    <Bubble
                                        key={message.id}
                                        message={message}
                                    />
                                ))}
                                <div ref={bottom} />
                            </div>
                            {requests.length > 0 && (
                                <div className="flex flex-col gap-2 border-t p-3">
                                    {requests.map((request) => (
                                        <RequestCard
                                            key={request.id}
                                            request={request}
                                            categories={requestCategories}
                                            canOverridePrice={canOverridePrice}
                                        />
                                    ))}
                                </div>
                            )}
                            <Reply
                                thread={conversation}
                                canReply={canReply}
                                connected={connected}
                                templates={templates}
                            />
                        </>
                    )}
                </section>
            </div>
        </>
    );
}

Inbox.layout = {
    breadcrumbs: [{ title: 'Inbox', href: InboxController.index() }],
};
