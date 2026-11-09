import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import HotelDemoController from '@/actions/App/Domains/Demo/Http/Controllers/HotelDemoController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { getJson, postJson } from '@/lib/http';
import { t } from '@/lib/i18n';

type Props = {
    hotel: string;
    expires: string | null;
    scenarios: { key: string; label: string }[];
    phone: { display: string; link: string; qr: string } | null;
    categories: [string, string, string, number, number][];
};
type Feed = {
    name: string | null;
    messages: { direction: string; body: string; at: string }[];
} | null;

export default function Demo({
    hotel,
    expires,
    scenarios,
    phone,
    categories,
}: Props) {
    const [wa, setWa] = useState<string | null>(null);
    const [feed, setFeed] = useState<Feed>(null);
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const timer = useRef<number | undefined>(undefined);
    const personalise = useForm({
        name: hotel,
        categories: categories.map(([, fr, , price, rooms]) => ({
            name: fr,
            price,
            rooms,
        })),
    });
    const errors = personalise.errors as Record<string, string | undefined>;

    // The live conversation: refreshed every 2 seconds while a scenario plays.
    useEffect(() => {
        window.clearInterval(timer.current);
        if (!wa) {
            return;
        }
        const load = () => {
            void getJson<{ conversation: Feed }>(
                `${HotelDemoController.feed.url()}?wa=${wa}`,
            )
                .then((r) => setFeed(r.conversation))
                .catch(() => undefined);
        };
        load();
        timer.current = window.setInterval(load, 2000);

        return () => window.clearInterval(timer.current);
    }, [wa]);

    const play = (body: { scenario?: string; text?: string }) => {
        setBusy(true);
        setFeed(null);
        void postJson<{ wa: string }>(HotelDemoController.play.url(), body)
            .then((r) => setWa(r.wa))
            .finally(() => setBusy(false));
    };

    return (
        <>
            <Head title={t('Demo')} />
            <div className="grid max-w-6xl gap-6 p-4 text-sm lg:grid-cols-2">
                <div className="flex flex-col gap-4">
                    <Heading
                        title={t('Demo: :hotel', { hotel })}
                        description={
                            expires
                                ? t('Private demo, available until :date.', {
                                      date: expires,
                                  })
                                : t(
                                      'Show the assistant live. Nothing is sent on WhatsApp; the assistant answers for real.',
                                  )
                        }
                    />
                    <section className="flex flex-col gap-2">
                        <h2 className="font-semibold">
                            {t('Play a scenario')}
                        </h2>
                        <div className="flex flex-wrap gap-2">
                            {scenarios.map((s) => (
                                <Button
                                    key={s.key}
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => play({ scenario: s.key })}
                                >
                                    {s.label}
                                </Button>
                            ))}
                        </div>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (text.trim()) {
                                    play({ text });
                                    setText('');
                                }
                            }}
                            className="flex gap-2"
                        >
                            <Input
                                aria-label={t('Write as a guest')}
                                placeholder={t('Write as a guest…')}
                                value={text}
                                onChange={(e) => setText(e.target.value)}
                            />
                            <Button
                                type="submit"
                                disabled={busy || !text.trim()}
                            >
                                {t('Send')}
                            </Button>
                        </form>
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'The booking request, order or quote then appears in Today, Restaurant or Events, as in real use.',
                            )}
                        </p>
                    </section>
                    {phone && (
                        <section className="flex flex-col gap-2 rounded-xl border p-3">
                            <h2 className="font-semibold">
                                {t('Try it on your own phone')}
                            </h2>
                            <p>
                                {t(
                                    'Write to :number on WhatsApp, or open this link on your phone:',
                                    { number: phone.display },
                                )}
                            </p>
                            <a
                                href={phone.link}
                                target="_blank"
                                rel="noreferrer"
                                className="break-all underline"
                            >
                                {phone.link}
                            </a>
                            <img
                                alt={t('QR code to open WhatsApp')}
                                className="size-40"
                                src={phone.qr}
                            />
                        </section>
                    )}
                    <details className="rounded-xl border p-3">
                        <summary className="cursor-pointer font-semibold">
                            {t('Show the prospect’s own hotel')}
                        </summary>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                personalise.post(
                                    HotelDemoController.personalise.url(),
                                    { preserveScroll: true },
                                );
                            }}
                            className="mt-2 flex flex-col gap-2"
                        >
                            <label className="grid gap-0.5 text-xs">
                                {t('Hotel name')}
                                <Input
                                    value={personalise.data.name}
                                    onChange={(e) =>
                                        personalise.setData(
                                            'name',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            {personalise.data.categories.map((c, i) => (
                                <div key={i} className="grid grid-cols-3 gap-2">
                                    <Input
                                        aria-label={t('Room type')}
                                        value={c.name}
                                        onChange={(e) =>
                                            personalise.setData(
                                                'categories',
                                                personalise.data.categories.map(
                                                    (x, j) =>
                                                        j === i
                                                            ? {
                                                                  ...x,
                                                                  name: e.target
                                                                      .value,
                                                              }
                                                            : x,
                                                ),
                                            )
                                        }
                                    />
                                    <Input
                                        aria-label={t('Price per night')}
                                        type="number"
                                        value={c.price}
                                        onChange={(e) =>
                                            personalise.setData(
                                                'categories',
                                                personalise.data.categories.map(
                                                    (x, j) =>
                                                        j === i
                                                            ? {
                                                                  ...x,
                                                                  price: Number(
                                                                      e.target
                                                                          .value,
                                                                  ),
                                                              }
                                                            : x,
                                                ),
                                            )
                                        }
                                    />
                                    <Input
                                        aria-label={t('Number of rooms')}
                                        type="number"
                                        value={c.rooms}
                                        onChange={(e) =>
                                            personalise.setData(
                                                'categories',
                                                personalise.data.categories.map(
                                                    (x, j) =>
                                                        j === i
                                                            ? {
                                                                  ...x,
                                                                  rooms: Number(
                                                                      e.target
                                                                          .value,
                                                                  ),
                                                              }
                                                            : x,
                                                ),
                                            )
                                        }
                                    />
                                </div>
                            ))}
                            <InputError
                                message={
                                    errors.name ?? errors['categories.0.price']
                                }
                            />
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        personalise.setData('categories', [
                                            ...personalise.data.categories,
                                            {
                                                name: '',
                                                price: 20000,
                                                rooms: 5,
                                            },
                                        ])
                                    }
                                >
                                    {t('Add a room type')}
                                </Button>
                                <Button
                                    size="sm"
                                    disabled={personalise.processing}
                                >
                                    {t('Apply to the demo')}
                                </Button>
                            </div>
                        </form>
                    </details>
                    <div>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                router.post(HotelDemoController.reset.url())
                            }
                        >
                            {t('Reset the demo')}
                        </Button>
                    </div>
                </div>
                <section
                    aria-live="polite"
                    className="flex min-h-80 flex-col gap-2 rounded-2xl border bg-muted/30 p-4"
                >
                    <h2 className="font-semibold">
                        {feed?.name
                            ? t('WhatsApp with :name', { name: feed.name })
                            : t('WhatsApp conversation')}
                    </h2>
                    {!wa && (
                        <p className="text-muted-foreground">
                            {t('Play a scenario to see the conversation here.')}
                        </p>
                    )}
                    {wa && !feed && (
                        <p className="text-muted-foreground">
                            {t('The guest is writing…')}
                        </p>
                    )}
                    {feed?.messages.map((m, i) => (
                        <p
                            key={i}
                            className={`max-w-[85%] rounded-2xl px-3 py-2 ${m.direction === 'out' ? 'self-end bg-primary text-primary-foreground' : 'self-start border bg-background'}`}
                        >
                            {m.body}
                            <span className="ml-2 text-[11px] opacity-80">
                                {m.at}
                            </span>
                        </p>
                    ))}
                    {feed &&
                        feed.messages.length > 0 &&
                        feed.messages[feed.messages.length - 1].direction ===
                            'in' && (
                            <p className="text-xs text-muted-foreground">
                                {t('The assistant is answering…')}
                            </p>
                        )}
                </section>
            </div>
        </>
    );
}

Demo.layout = {
    breadcrumbs: [{ title: 'Demo', href: HotelDemoController.show() }],
};
