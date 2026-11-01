import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import RoomCategoryController from '@/actions/App/Domains/Rooms/Http/Controllers/RoomCategoryController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

export type Category = {
    id: string;
    code: string;
    name: Record<string, string>;
    description: Record<string, string> | null;
    standard_occupancy: number;
    max_adults: number;
    max_children: number;
    max_occupancy: number;
    extra_bed_allowed: boolean;
    amenities: string[];
    active: boolean;
    sort_order: number;
    display_name: string;
    rooms_count: number;
    sellable_tonight: number;
};

export type Option = { value: string; label: string };

type Props = {
    category?: Category;
    locales: string[];
    defaultLocale: string;
    amenities: Option[];
    onDone: () => void;
};

const blank = (locales: string[]) =>
    Object.fromEntries(locales.map((l) => [l, ''])) as Record<string, string>;

export function CategoryForm({
    category,
    locales,
    defaultLocale,
    amenities,
    onDone,
}: Props) {
    const form = useForm({
        code: category?.code ?? '',
        name: { ...blank(locales), ...category?.name },
        description: { ...blank(locales), ...category?.description },
        standard_occupancy: category?.standard_occupancy ?? 2,
        max_adults: category?.max_adults ?? 2,
        max_children: category?.max_children ?? 1,
        max_occupancy: category?.max_occupancy ?? 3,
        extra_bed_allowed: category?.extra_bed_allowed ?? false,
        amenities: category?.amenities ?? [],
        active: category?.active ?? true,
        sort_order: category?.sort_order ?? 0,
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onDone() };

        if (category) {
            form.put(RoomCategoryController.update.url(category.id), options);
        } else {
            form.post(RoomCategoryController.store.url(), options);
        }
    };

    const number = (
        key:
            | 'standard_occupancy'
            | 'max_adults'
            | 'max_children'
            | 'max_occupancy',
        label: string,
        min = 1,
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={`${key}-${category?.id ?? 'new'}`}>{label}</Label>
            <Input
                id={`${key}-${category?.id ?? 'new'}`}
                type="number"
                min={min}
                max={20}
                value={form.data[key]}
                onChange={(e) => form.setData(key, Number(e.target.value))}
            />
            <InputError message={errors[key]} />
        </div>
    );

    return (
        <form
            onSubmit={submit}
            className="flex flex-col gap-4 rounded-xl border p-4"
        >
            <div className="grid gap-4 sm:grid-cols-4">
                <div className="grid gap-2">
                    <Label htmlFor={`code-${category?.id ?? 'new'}`}>
                        {t('Code')}
                    </Label>
                    <Input
                        id={`code-${category?.id ?? 'new'}`}
                        value={form.data.code}
                        maxLength={10}
                        placeholder="EXE"
                        onChange={(e) =>
                            form.setData('code', e.target.value.toUpperCase())
                        }
                        required
                    />
                    <InputError message={errors.code} />
                </div>
                {locales.map((locale) => (
                    <div
                        key={locale}
                        className="grid gap-2 sm:col-span-3 sm:first-of-type:col-span-3"
                    >
                        <Label
                            htmlFor={`name-${locale}-${category?.id ?? 'new'}`}
                        >
                            {t('Name (:locale)', {
                                locale: locale.toUpperCase(),
                            })}
                        </Label>
                        <Input
                            id={`name-${locale}-${category?.id ?? 'new'}`}
                            value={form.data.name[locale] ?? ''}
                            required={locale === defaultLocale}
                            onChange={(e) =>
                                form.setData('name', {
                                    ...form.data.name,
                                    [locale]: e.target.value,
                                })
                            }
                        />
                        <InputError message={errors[`name.${locale}`]} />
                    </div>
                ))}
            </div>

            {locales.map((locale) => (
                <div key={locale} className="grid gap-2">
                    <Label
                        htmlFor={`description-${locale}-${category?.id ?? 'new'}`}
                    >
                        {t('Description (:locale)', {
                            locale: locale.toUpperCase(),
                        })}
                    </Label>
                    <textarea
                        id={`description-${locale}-${category?.id ?? 'new'}`}
                        rows={2}
                        maxLength={1000}
                        className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        value={form.data.description[locale] ?? ''}
                        onChange={(e) =>
                            form.setData('description', {
                                ...form.data.description,
                                [locale]: e.target.value,
                            })
                        }
                    />
                </div>
            ))}

            <div className="grid gap-4 sm:grid-cols-4">
                {number('standard_occupancy', t('Guests included in price'))}
                {number('max_adults', t('Maximum adults'))}
                {number('max_children', t('Maximum children'), 0)}
                {number('max_occupancy', t('Maximum guests'))}
            </div>

            <div className="grid gap-2">
                <Label>{t('Amenities')}</Label>
                <div className="flex flex-wrap gap-x-4 gap-y-2">
                    {amenities.map((amenity) => (
                        <label
                            key={amenity.value}
                            className="flex items-center gap-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={form.data.amenities.includes(
                                    amenity.value,
                                )}
                                onChange={(e) =>
                                    form.setData(
                                        'amenities',
                                        e.target.checked
                                            ? [
                                                  ...form.data.amenities,
                                                  amenity.value,
                                              ]
                                            : form.data.amenities.filter(
                                                  (a) => a !== amenity.value,
                                              ),
                                    )
                                }
                            />
                            {amenity.label}
                        </label>
                    ))}
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-6 text-sm">
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        className="size-4"
                        checked={form.data.extra_bed_allowed}
                        onChange={(e) =>
                            form.setData('extra_bed_allowed', e.target.checked)
                        }
                    />
                    {t('Extra bed possible')}
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        className="size-4"
                        checked={form.data.active}
                        onChange={(e) =>
                            form.setData('active', e.target.checked)
                        }
                    />
                    {t('On sale')}
                </label>
                <div className="flex items-center gap-2">
                    <Label htmlFor={`sort-${category?.id ?? 'new'}`}>
                        {t('Order')}
                    </Label>
                    <Input
                        id={`sort-${category?.id ?? 'new'}`}
                        type="number"
                        min={0}
                        className="h-8 w-20"
                        value={form.data.sort_order}
                        onChange={(e) =>
                            form.setData('sort_order', Number(e.target.value))
                        }
                    />
                </div>
            </div>

            <div className="flex gap-2">
                <Button disabled={form.processing}>
                    {category ? t('Save category') : t('Add category')}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
            </div>
        </form>
    );
}
