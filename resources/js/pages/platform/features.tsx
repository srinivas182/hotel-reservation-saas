import { Head, useForm } from '@inertiajs/react';
import FeatureController from '@/actions/App/Domains/Platform/Http/Controllers/FeatureController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

type FeatureRow = {
    key: string;
    label: string;
    mode: string;
    countries: string[];
    plans: string[];
    hotels: string[];
};
type Props = {
    features: FeatureRow[];
    countries: string[];
    plans: string[];
    hotels: { id: string; name: string }[];
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

function FeatureForm({
    feature,
    props,
}: {
    feature: FeatureRow;
    props: Props;
}) {
    const form = useForm({
        mode: feature.mode,
        countries: feature.countries,
        plans: feature.plans,
        hotels: feature.hotels,
    });
    const multi = (
        key: 'countries' | 'plans' | 'hotels',
        label: string,
        options: { value: string; label: string }[],
    ) => (
        <label className="grid gap-0.5 text-xs">
            {label}
            <select
                multiple
                className="h-20 rounded-md border border-input bg-transparent px-2 text-xs"
                value={form.data[key]}
                onChange={(e) =>
                    form.setData(
                        key,
                        Array.from(e.target.selectedOptions).map(
                            (o) => o.value,
                        ),
                    )
                }
            >
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        </label>
    );

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.put(FeatureController.update.url(feature.key), {
                    preserveScroll: true,
                });
            }}
            className="flex flex-wrap items-end gap-3 rounded-xl border p-3 text-sm"
        >
            <div className="min-w-56 flex-1 font-medium">{feature.label}</div>
            <select
                aria-label={t('Mode')}
                className={selectClass}
                value={form.data.mode}
                onChange={(e) => form.setData('mode', e.target.value)}
            >
                <option value="on">{t('On for everyone')}</option>
                <option value="off">{t('Off')}</option>
                <option value="targeted">
                    {t('On for chosen countries, plans or hotels')}
                </option>
            </select>
            {form.data.mode === 'targeted' && (
                <>
                    {multi(
                        'countries',
                        t('Countries'),
                        props.countries.map((c) => ({ value: c, label: c })),
                    )}
                    {multi(
                        'plans',
                        t('Plans'),
                        props.plans.map((p) => ({ value: p, label: p })),
                    )}
                    {multi(
                        'hotels',
                        t('Hotels'),
                        props.hotels.map((h) => ({
                            value: h.id,
                            label: h.name,
                        })),
                    )}
                </>
            )}
            <Button size="sm" disabled={form.processing}>
                {t('Save')}
            </Button>
        </form>
    );
}

export default function Features(props: Props) {
    return (
        <>
            <Head title={t('Feature switches')} />
            <div className="flex max-w-5xl flex-col gap-3 p-4">
                <Heading
                    title={t('Feature switches')}
                    description={t(
                        'Roll new features out to a few hotels first. A feature must also be in the hotel’s plan.',
                    )}
                />
                {props.features.map((f) => (
                    <FeatureForm key={f.key} feature={f} props={props} />
                ))}
            </div>
        </>
    );
}

Features.layout = {
    breadcrumbs: [
        { title: 'Feature switches', href: FeatureController.index() },
    ],
};
