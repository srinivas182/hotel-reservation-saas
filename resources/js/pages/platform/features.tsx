import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import FeatureController from '@/actions/App/Domains/Platform/Http/Controllers/FeatureController';
import {
    ChipSelect,
    ChoiceCards,
    DataTable,
    Field,
    FieldGroup,
    PageBody,
    PageHeader,
    Section,
    SidePanel,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
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

const modeLabel = (mode: string) =>
    ({
        on: t('On for everyone'),
        off: t('Off'),
        targeted: t('Chosen hotels only'),
    })[mode] ?? mode;

function FeatureForm({
    feature,
    props,
    onDone,
}: {
    feature: FeatureRow;
    props: Props;
    onDone: () => void;
}) {
    const form = useForm({
        mode: feature.mode,
        countries: feature.countries,
        plans: feature.plans,
        hotels: feature.hotels,
    });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.put(FeatureController.update.url(feature.key), {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
            className="grid gap-6"
        >
            <FieldGroup title={t('Who gets it')}>
                <ChoiceCards
                    label={t('Mode')}
                    value={form.data.mode}
                    onChange={(v) => form.setData('mode', v)}
                    options={[
                        {
                            value: 'on',
                            label: t('On for everyone'),
                            hint: t('Every hotel whose plan includes it.'),
                        },
                        {
                            value: 'targeted',
                            label: t(
                                'On for chosen countries, plans or hotels',
                            ),
                            hint: t(
                                'To try it with a few hotels before everyone.',
                            ),
                        },
                        {
                            value: 'off',
                            label: t('Off'),
                            hint: t('Nobody sees it.'),
                        },
                    ]}
                />
            </FieldGroup>
            {form.data.mode === 'targeted' && (
                <FieldGroup
                    title={t('Chosen')}
                    description={t(
                        'A hotel gets the feature when it matches any of these.',
                    )}
                >
                    <Field label={t('Countries')}>
                        <ChipSelect
                            label={t('Countries')}
                            value={form.data.countries}
                            onChange={(v) => form.setData('countries', v)}
                            options={props.countries.map((c) => ({
                                value: c,
                                label: c,
                            }))}
                        />
                    </Field>
                    <Field label={t('Plans')}>
                        <ChipSelect
                            label={t('Plans')}
                            value={form.data.plans}
                            onChange={(v) => form.setData('plans', v)}
                            options={props.plans.map((p) => ({
                                value: p,
                                label: p,
                            }))}
                        />
                    </Field>
                    <Field label={t('Hotels')}>
                        <ChipSelect
                            label={t('Hotels')}
                            value={form.data.hotels}
                            onChange={(v) => form.setData('hotels', v)}
                            options={props.hotels.map((h) => ({
                                value: h.id,
                                label: h.name,
                            }))}
                        />
                    </Field>
                </FieldGroup>
            )}
            <div className="flex justify-end gap-2 border-t pt-4">
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
                <Button disabled={form.processing}>{t('Save')}</Button>
            </div>
        </form>
    );
}

export default function Features(props: Props) {
    const [editing, setEditing] = useState<FeatureRow | null>(null);
    const hotelName = (id: string) =>
        props.hotels.find((h) => h.id === id)?.name ?? id;

    return (
        <>
            <Head title={t('Feature switches')} />
            <PageBody>
                <PageHeader
                    title={t('Feature switches')}
                    description={t(
                        'Roll new features out to a few hotels first. A feature must also be in the hotel’s plan.',
                    )}
                />
                <Section
                    flush
                    title={t('Features')}
                    description={t('Click a feature to change who gets it.')}
                >
                    <DataTable
                        rows={props.features}
                        rowKey={(f) => f.key}
                        empty="—"
                        onRowClick={setEditing}
                        columns={[
                            {
                                key: 'label',
                                header: t('Feature'),
                                cell: (f) => (
                                    <span className="font-medium">
                                        {f.label}
                                    </span>
                                ),
                            },
                            {
                                key: 'mode',
                                header: t('Status'),
                                cell: (f) => (
                                    <Badge
                                        variant={
                                            f.mode === 'on'
                                                ? 'default'
                                                : f.mode === 'off'
                                                  ? 'outline'
                                                  : 'secondary'
                                        }
                                    >
                                        {modeLabel(f.mode)}
                                    </Badge>
                                ),
                            },
                            {
                                key: 'who',
                                header: t('Chosen'),
                                cell: (f) =>
                                    f.mode === 'targeted' ? (
                                        <span className="text-muted-foreground">
                                            {[
                                                ...f.countries,
                                                ...f.plans,
                                                ...f.hotels.map(hotelName),
                                            ].join(', ') || '—'}
                                        </span>
                                    ) : (
                                        '—'
                                    ),
                            },
                            {
                                key: 'edit',
                                header: '',
                                align: 'right',
                                cell: () => (
                                    <span className="text-xs text-brand">
                                        {t('Edit')}
                                    </span>
                                ),
                            },
                        ]}
                    />
                </Section>
            </PageBody>
            <SidePanel
                open={editing !== null}
                onClose={() => setEditing(null)}
                title={editing?.label ?? ''}
            >
                {editing && (
                    <FeatureForm
                        key={editing.key}
                        feature={editing}
                        props={props}
                        onDone={() => setEditing(null)}
                    />
                )}
            </SidePanel>
        </>
    );
}

Features.layout = {
    breadcrumbs: [
        { title: 'Feature switches', href: FeatureController.index() },
    ],
};
