import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ReadinessController from '@/actions/App/Domains/Platform/Http/Controllers/ReadinessController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type HotelRow = {
    id: string;
    name: string;
    mode: string;
    overridden: boolean;
    override_reason: string | null;
    missing: string[];
    recommended_missing: number;
};

function OverrideForm({
    hotel,
    onDone,
}: {
    hotel: HotelRow;
    onDone: () => void;
}) {
    const form = useForm({ reason: '' });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(ReadinessController.override.url(hotel.id), {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
            className="mt-2 flex flex-wrap items-start gap-2"
        >
            <Input
                className="w-96"
                aria-label={t('Reason')}
                placeholder={t('Why go live without every required check?')}
                value={form.data.reason}
                onChange={(e) => form.setData('reason', e.target.value)}
            />
            <Button size="sm" variant="destructive" disabled={form.processing}>
                {t('Allow go-live')}
            </Button>
            <InputError message={form.errors.reason} />
        </form>
    );
}

export default function PlatformReadiness({ hotels }: { hotels: HotelRow[] }) {
    const [overriding, setOverriding] = useState<string | null>(null);

    return (
        <>
            <Head title={t('Go-live readiness')} />
            <div className="flex max-w-5xl flex-col gap-6 p-4">
                <Heading
                    title={t('Go-live readiness')}
                    description={t(
                        'Required checks still missing for each hotel.',
                    )}
                />
                <ul className="divide-y rounded-xl border">
                    {hotels.map((hotel) => (
                        <li key={hotel.id} className="px-4 py-3 text-sm">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">
                                    {hotel.name}
                                </span>
                                <Badge
                                    variant={
                                        hotel.missing.length === 0
                                            ? 'default'
                                            : 'destructive'
                                    }
                                >
                                    {hotel.missing.length === 0
                                        ? t('Ready')
                                        : t(':count missing', {
                                              count: hotel.missing.length,
                                          })}
                                </Badge>
                                <span className="text-muted-foreground">
                                    {hotel.mode}
                                </span>
                                {hotel.overridden ? (
                                    <>
                                        <Badge variant="outline">
                                            {t('Allowed by override: :reason', {
                                                reason:
                                                    hotel.override_reason ?? '',
                                            })}
                                        </Badge>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                router.post(
                                                    ReadinessController.override.url(
                                                        hotel.id,
                                                    ),
                                                    { remove: true },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('Remove override')}
                                        </Button>
                                    </>
                                ) : (
                                    hotel.missing.length > 0 && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                setOverriding(hotel.id)
                                            }
                                        >
                                            {t('Override')}
                                        </Button>
                                    )
                                )}
                            </div>
                            {hotel.missing.length > 0 && (
                                <div className="text-muted-foreground">
                                    {hotel.missing.join(' · ')}
                                </div>
                            )}
                            {overriding === hotel.id && (
                                <OverrideForm
                                    hotel={hotel}
                                    onDone={() => setOverriding(null)}
                                />
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

PlatformReadiness.layout = {
    breadcrumbs: [
        { title: 'Go-live readiness', href: ReadinessController.index() },
    ],
};
