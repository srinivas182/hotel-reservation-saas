import { Head, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import TaxRateController from '@/actions/App/Domains/Finance/Http/Controllers/TaxRateController';
import {
    DataTable,
    PageBody,
    PageHeader,
    Section,
    SidePanel,
} from '@/components/page';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { askConfirm } from '@/lib/ask';

type Option = { value: string; label: string };

type Rate = {
    id: string;
    name: Record<string, string>;
    display_name: string;
    type: string;
    type_label: string;
    percentage: number | null;
    amount: string | null;
    amount_display: string | null;
    inclusive: boolean;
    applies_to: string[];
    active: boolean;
    effective_from: string | null;
    effective_to: string | null;
    sort_order: number;
};

type Preview = {
    net: string;
    lines: { name: string; amount: string; inclusive: boolean }[];
    included_tax: string;
    added_tax: string;
    total: string;
} | null;

type Props = {
    currency: string;
    locales: string[];
    defaultLocale: string;
    rates: Rate[];
    types: Option[];
    scopes: Option[];
    preview?: Preview;
    canManage: boolean;
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

function emptyForm(locales: string[]) {
    return {
        name: Object.fromEntries(locales.map((l) => [l, ''])) as Record<
            string,
            string
        >,
        type: 'percentage',
        percentage: '' as string | number,
        amount: '' as string,
        inclusive: false,
        applies_to: ['rooms'] as string[],
        active: true,
        effective_from: '',
        effective_to: '',
        sort_order: 0,
    };
}

function TaxForm({
    props,
    rate,
    onDone,
}: {
    props: Props;
    rate?: Rate;
    onDone: () => void;
}) {
    const form = useForm(
        rate
            ? {
                  name: {
                      ...Object.fromEntries(props.locales.map((l) => [l, ''])),
                      ...rate.name,
                  },
                  type: rate.type,
                  percentage: rate.percentage ?? '',
                  amount: rate.amount ?? '',
                  inclusive: rate.inclusive,
                  applies_to: rate.applies_to,
                  active: rate.active,
                  effective_from: rate.effective_from ?? '',
                  effective_to: rate.effective_to ?? '',
                  sort_order: rate.sort_order,
              }
            : emptyForm(props.locales),
    );
    const { data, setData, processing } = form;
    const errors = form.errors as Record<string, string | undefined>;
    const isPercentage = data.type === 'percentage';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({
            ...values,
            percentage: isPercentage ? values.percentage : null,
            amount: isPercentage ? null : values.amount,
            effective_from: values.effective_from || null,
            effective_to: values.effective_to || null,
        }));
        const options = { preserveScroll: true, onSuccess: () => onDone() };

        if (rate) {
            form.put(TaxRateController.update.url(rate.id), options);
        } else {
            form.post(TaxRateController.store.url(), options);
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-5">
            <div className="grid gap-4 sm:grid-cols-2">
                {props.locales.map((locale) => (
                    <div key={locale} className="grid gap-2">
                        <Label htmlFor={`name-${locale}`}>
                            {locale === props.defaultLocale
                                ? t('Name (:language)', {
                                      language: locale.toUpperCase(),
                                  })
                                : t('Name (:language, optional)', {
                                      language: locale.toUpperCase(),
                                  })}
                        </Label>
                        <Input
                            id={`name-${locale}`}
                            value={data.name[locale] ?? ''}
                            onChange={(e) =>
                                setData('name', {
                                    ...data.name,
                                    [locale]: e.target.value,
                                })
                            }
                            required={locale === props.defaultLocale}
                        />
                        <InputError message={errors[`name.${locale}`]} />
                    </div>
                ))}
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
                <div className="grid gap-2">
                    <Label htmlFor="type">{t('Type')}</Label>
                    <select
                        id="type"
                        className={selectClass}
                        value={data.type}
                        onChange={(e) => setData('type', e.target.value)}
                    >
                        {props.types.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                    </select>
                </div>
                {isPercentage ? (
                    <div className="grid gap-2">
                        <Label htmlFor="percentage">{t('Rate (%)')}</Label>
                        <Input
                            id="percentage"
                            type="number"
                            step="0.01"
                            min="0"
                            max="100"
                            value={data.percentage}
                            onChange={(e) =>
                                setData('percentage', e.target.value)
                            }
                            required
                        />
                        <InputError message={errors.percentage} />
                    </div>
                ) : (
                    <div className="grid gap-2">
                        <Label htmlFor="amount">
                            {t('Amount (:currency)', {
                                currency: props.currency,
                            })}
                        </Label>
                        <Input
                            id="amount"
                            type="number"
                            step="any"
                            min="0"
                            value={data.amount}
                            onChange={(e) => setData('amount', e.target.value)}
                            required
                        />
                        <InputError message={errors.amount} />
                    </div>
                )}
                <div className="grid gap-2">
                    <Label htmlFor="inclusive">{t('Price display')}</Label>
                    <select
                        id="inclusive"
                        className={selectClass}
                        value={data.inclusive ? '1' : '0'}
                        onChange={(e) =>
                            setData('inclusive', e.target.value === '1')
                        }
                    >
                        <option value="0">{t('Added on top of prices')}</option>
                        <option value="1">{t('Included in prices')}</option>
                    </select>
                </div>
            </div>

            <div className="grid gap-2">
                <Label>{t('Applies to')}</Label>
                <div className="flex flex-wrap gap-4">
                    {props.scopes.map((scope) => (
                        <label
                            key={scope.value}
                            className="flex items-center gap-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={data.applies_to.includes(scope.value)}
                                onChange={(e) =>
                                    setData(
                                        'applies_to',
                                        e.target.checked
                                            ? [...data.applies_to, scope.value]
                                            : data.applies_to.filter(
                                                  (s) => s !== scope.value,
                                              ),
                                    )
                                }
                            />
                            {scope.label}
                        </label>
                    ))}
                </div>
                <InputError message={errors.applies_to} />
            </div>

            <div className="grid gap-4 sm:grid-cols-4">
                <div className="grid gap-2">
                    <Label htmlFor="effective_from">
                        {t('From (optional)')}
                    </Label>
                    <Input
                        id="effective_from"
                        type="date"
                        value={data.effective_from}
                        onChange={(e) =>
                            setData('effective_from', e.target.value)
                        }
                    />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="effective_to">
                        {t('Until (optional)')}
                    </Label>
                    <Input
                        id="effective_to"
                        type="date"
                        value={data.effective_to}
                        onChange={(e) =>
                            setData('effective_to', e.target.value)
                        }
                    />
                    <InputError message={errors.effective_to} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="sort_order">{t('Order')}</Label>
                    <Input
                        id="sort_order"
                        type="number"
                        min="0"
                        value={data.sort_order}
                        onChange={(e) =>
                            setData('sort_order', Number(e.target.value))
                        }
                    />
                </div>
                <label className="flex items-center gap-2 self-end pb-2 text-sm">
                    <input
                        type="checkbox"
                        className="size-4"
                        checked={data.active}
                        onChange={(e) => setData('active', e.target.checked)}
                    />
                    {t('Active')}
                </label>
            </div>

            <div className="flex justify-end gap-2 border-t pt-4">
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
                <Button disabled={processing}>
                    {rate ? t('Save tax') : t('Add tax')}
                </Button>
            </div>
        </form>
    );
}

export default function Taxes(props: Props) {
    const { rates, canManage, scopes, preview } = props;
    const [editing, setEditing] = useState<string | null>(null);
    const [example, setExample] = useState({
        price: '50000',
        nights: '2',
        guests: '2',
        scope: 'rooms',
    });

    const runPreview = (event: FormEvent) => {
        event.preventDefault();
        router.reload({ only: ['preview'], data: example });
    };

    return (
        <>
            <Head title={t('Taxes')} />
            <PageBody>
                <PageHeader
                    title={t('Taxes')}
                    description={t(
                        'VAT, tourist tax and service charges applied to your prices.',
                    )}
                    actions={
                        canManage && (
                            <Button onClick={() => setEditing('new')}>
                                <Plus /> {t('Add tax')}
                            </Button>
                        )
                    }
                />

                <Section
                    flush
                    title={t('Taxes')}
                    description={
                        canManage ? t('Click a tax to edit it.') : undefined
                    }
                >
                    <DataTable
                        rows={rates}
                        rowKey={(r) => r.id}
                        empty={t('No taxes yet.')}
                        onRowClick={
                            canManage ? (r) => setEditing(r.id) : undefined
                        }
                        columns={[
                            {
                                key: 'name',
                                header: t('Tax'),
                                cell: (r) => (
                                    <div>
                                        <div className="font-medium">
                                            {r.display_name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {r.type_label}
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'rate',
                                header: t('Rate'),
                                align: 'right',
                                cell: (r) => (
                                    <span className="font-semibold">
                                        {r.percentage !== null
                                            ? `${r.percentage}%`
                                            : r.amount_display}
                                    </span>
                                ),
                            },
                            {
                                key: 'scope',
                                header: t('Applies to'),
                                cell: (r) =>
                                    r.applies_to
                                        .map(
                                            (x) =>
                                                scopes.find(
                                                    (o) => o.value === x,
                                                )?.label ?? x,
                                        )
                                        .join(', '),
                            },
                            {
                                key: 'status',
                                header: t('Status'),
                                cell: (r) => (
                                    <div className="flex flex-wrap gap-1">
                                        <Badge variant="outline">
                                            {r.inclusive
                                                ? t('Included')
                                                : t('Added on top')}
                                        </Badge>
                                        {!r.active && (
                                            <Badge variant="secondary">
                                                {t('Inactive')}
                                            </Badge>
                                        )}
                                    </div>
                                ),
                            },
                            ...(canManage
                                ? [
                                      {
                                          key: 'delete',
                                          header: '',
                                          align: 'right' as const,
                                          cell: (r: Rate) => (
                                              <Button
                                                  variant="ghost"
                                                  size="sm"
                                                  className="text-destructive"
                                                  onClick={async (e) => {
                                                      e.stopPropagation();
                                                      if (
                                                          await askConfirm(
                                                              t(
                                                                  'Delete :name?',
                                                                  {
                                                                      name: r.display_name,
                                                                  },
                                                              ),
                                                          )
                                                      ) {
                                                          router.delete(
                                                              TaxRateController.destroy.url(
                                                                  r.id,
                                                              ),
                                                              {
                                                                  preserveScroll: true,
                                                              },
                                                          );
                                                      }
                                                  }}
                                              >
                                                  {t('Delete')}
                                              </Button>
                                          ),
                                      },
                                  ]
                                : []),
                        ]}
                    />
                </Section>

                <Section
                    title={t('Try it')}
                    description={t(
                        "See how today's active taxes apply to a price.",
                    )}
                >
                    <form
                        onSubmit={runPreview}
                        className="grid gap-3 sm:grid-cols-5 sm:items-end"
                    >
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="preview-price">
                                {t('Price (:currency, whole stay)', {
                                    currency: props.currency,
                                })}
                            </Label>
                            <Input
                                id="preview-price"
                                type="number"
                                min="0"
                                value={example.price}
                                onChange={(e) =>
                                    setExample({
                                        ...example,
                                        price: e.target.value,
                                    })
                                }
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="preview-nights">
                                {t('Nights')}
                            </Label>
                            <Input
                                id="preview-nights"
                                type="number"
                                min="1"
                                value={example.nights}
                                onChange={(e) =>
                                    setExample({
                                        ...example,
                                        nights: e.target.value,
                                    })
                                }
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="preview-guests">
                                {t('Guests')}
                            </Label>
                            <Input
                                id="preview-guests"
                                type="number"
                                min="1"
                                value={example.guests}
                                onChange={(e) =>
                                    setExample({
                                        ...example,
                                        guests: e.target.value,
                                    })
                                }
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="preview-scope">{t('For')}</Label>
                            <select
                                id="preview-scope"
                                className={selectClass}
                                value={example.scope}
                                onChange={(e) =>
                                    setExample({
                                        ...example,
                                        scope: e.target.value,
                                    })
                                }
                            >
                                {scopes.map((scope) => (
                                    <option
                                        key={scope.value}
                                        value={scope.value}
                                    >
                                        {scope.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <Button className="sm:col-span-5 sm:w-fit">
                            {t('Calculate')}
                        </Button>
                    </form>

                    {preview && (
                        <dl className="grid gap-1 text-sm">
                            <div className="flex justify-between">
                                <dt className="text-muted-foreground">
                                    {t('Price without tax')}
                                </dt>
                                <dd>{preview.net}</dd>
                            </div>
                            {preview.lines.map((line, index) => (
                                <div
                                    key={index}
                                    className="flex justify-between"
                                >
                                    <dt className="text-muted-foreground">
                                        {line.name}{' '}
                                        {line.inclusive
                                            ? '(included)'
                                            : '(added)'}
                                    </dt>
                                    <dd>{line.amount}</dd>
                                </div>
                            ))}
                            <div className="mt-1 flex justify-between border-t pt-2 font-medium">
                                <dt>{t('Guest pays')}</dt>
                                <dd>{preview.total}</dd>
                            </div>
                        </dl>
                    )}
                </Section>
            </PageBody>
            <SidePanel
                open={editing !== null}
                onClose={() => setEditing(null)}
                title={
                    editing === 'new'
                        ? t('Add tax')
                        : (rates.find((r) => r.id === editing)?.display_name ??
                          '')
                }
            >
                {editing !== null && (
                    <TaxForm
                        key={editing}
                        props={props}
                        rate={rates.find((r) => r.id === editing)}
                        onDone={() => setEditing(null)}
                    />
                )}
            </SidePanel>
        </>
    );
}

Taxes.layout = {
    breadcrumbs: [{ title: 'Taxes', href: TaxRateController.index() }],
};
