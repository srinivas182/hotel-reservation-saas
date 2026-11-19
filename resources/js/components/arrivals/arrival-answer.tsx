import { Link } from '@inertiajs/react';
import { Phone } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

export type ArrivalAnswer = {
    state: 'confirmed' | 'declined' | 'waiting' | null;
    eta: string | null;
    extras: string[];
    question: boolean;
    flagged: boolean;
};

export type TomorrowArrival = {
    id: string;
    reference: string;
    guest: string | null;
    phone: string | null;
    state: 'confirmed' | 'declined' | 'waiting' | null;
    eta: string | null;
    flagged: boolean;
};

/** What the guest answered to the arrival card (Sprint G), under a booking line. */
export function ArrivalAnswerLine({ answer }: { answer: ArrivalAnswer }) {
    return (
        <span className="mt-1 flex flex-wrap items-center gap-1.5">
            {answer.state === 'confirmed' && (
                <Badge variant="secondary">
                    ✓ {t('Confirmed by the guest')}
                </Badge>
            )}
            {answer.state === 'declined' && (
                <Badge variant="destructive">{t('Says not coming')}</Badge>
            )}
            {answer.state === 'waiting' && (
                <Badge variant="outline">{t('No answer yet')}</Badge>
            )}
            {answer.eta && (
                <span className="text-xs text-muted-foreground">
                    🕑 {answer.eta}
                </span>
            )}
            {answer.extras.map((extra) => (
                <Badge key={extra} variant="outline">
                    {extra}
                </Badge>
            ))}
            {answer.question && (
                <span className="text-xs text-muted-foreground">
                    {t('asked a question')}
                </span>
            )}
        </span>
    );
}

/**
 * Tomorrow's arrivals and their answer (Sprint G): those without an answer
 * first, with a button to call them.
 */
export function TomorrowCard({ items }: { items: TomorrowArrival[] }) {
    if (items.length === 0) {
        return null;
    }

    const order = { waiting: 0, declined: 1, confirmed: 2 } as const;
    const sorted = [...items].sort(
        (a, b) => order[a.state ?? 'waiting'] - order[b.state ?? 'waiting'],
    );
    const silent = items.filter((i) => i.state === 'waiting').length;

    return (
        <section
            id="tomorrow"
            aria-labelledby="tomorrow-heading"
            className="flex flex-col gap-2 rounded-xl border p-4"
        >
            <div className="flex items-center justify-between gap-2">
                <h2 id="tomorrow-heading" className="font-medium">
                    {t('Tomorrow’s arrivals')}
                </h2>
                <span className="text-sm text-muted-foreground">
                    {silent > 0
                        ? t(':count without an answer', { count: silent })
                        : t('All answered')}
                </span>
            </div>
            <ul className="divide-y text-sm">
                {sorted.map((item) => (
                    <li
                        key={item.id}
                        className="flex flex-wrap items-center gap-2 py-2"
                    >
                        <div className="min-w-0 flex-1">
                            <Link
                                href={`/hotel/bookings/${item.id}`}
                                className="font-medium hover:underline"
                            >
                                {item.guest}
                            </Link>{' '}
                            <span className="text-muted-foreground">
                                · {item.reference}
                            </span>
                            <ArrivalAnswerLine
                                answer={{
                                    state: item.state,
                                    eta: item.eta,
                                    extras: [],
                                    question: false,
                                    flagged: item.flagged,
                                }}
                            />
                        </div>
                        {item.state === 'waiting' && item.phone && (
                            <Button asChild size="sm" variant="outline">
                                <a href={`tel:${item.phone}`}>
                                    <Phone aria-hidden />
                                    {t('Call :name', {
                                        name: item.guest ?? '',
                                    })}
                                </a>
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}
