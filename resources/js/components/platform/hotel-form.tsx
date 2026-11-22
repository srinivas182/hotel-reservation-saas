import { Form } from '@inertiajs/react';
import type { RouteFormDefinition } from '@/wayfinder';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

export type HotelFormValues = {
    name: string;
    slug: string;
    legal_name: string | null;
    country_code: string;
    timezone: string;
    currency: string;
    default_locale: string;
    status?: string;
    owner_email: string | null;
};

type Props = {
    action: RouteFormDefinition<'post'>;
    values?: Partial<HotelFormValues>;
    statuses: string[];
    locales: string[];
    timezones: string[];
    showStatus: boolean;
    readOnly?: boolean;
    submitLabel: string;
};

const selectClass =
    'mt-1 flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50';

export function HotelForm({
    action,
    values = {},
    statuses,
    locales,
    timezones,
    showStatus,
    readOnly = false,
    submitLabel,
}: Props) {
    return (
        <Form
            {...action}
            options={{ preserveScroll: true }}
            className="space-y-6"
        >
            {({ processing, errors }) => (
                <fieldset disabled={readOnly} className="space-y-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="name">{t('Hotel name')}</Label>
                            <Input
                                id="name"
                                name="name"
                                defaultValue={values.name}
                                required
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="slug">{t('Slug')}</Label>
                            <Input
                                id="slug"
                                name="slug"
                                defaultValue={values.slug}
                                placeholder={t(
                                    'Generated from the name if empty',
                                )}
                            />
                            <InputError message={errors.slug} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="legal_name">
                            {t('Legal name (optional)')}
                        </Label>
                        <Input
                            id="legal_name"
                            name="legal_name"
                            defaultValue={values.legal_name ?? ''}
                        />
                        <InputError message={errors.legal_name} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="country_code">
                                {t('Country code')}
                            </Label>
                            <Input
                                id="country_code"
                                name="country_code"
                                defaultValue={values.country_code}
                                maxLength={2}
                                placeholder={'CI'}
                                required
                            />
                            <InputError message={errors.country_code} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="currency">{t('Currency')}</Label>
                            <Input
                                id="currency"
                                name="currency"
                                defaultValue={values.currency}
                                maxLength={3}
                                placeholder={'XOF'}
                                required
                            />
                            <InputError message={errors.currency} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="default_locale">
                                {t('Default language')}
                            </Label>
                            <select
                                id="default_locale"
                                name="default_locale"
                                defaultValue={values.default_locale ?? 'en'}
                                className={selectClass}
                            >
                                {locales.map((locale) => (
                                    <option key={locale} value={locale}>
                                        {locale.toUpperCase()}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.default_locale} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="timezone">{t('Time zone')}</Label>
                            <Input
                                id="timezone"
                                name="timezone"
                                list="timezones"
                                defaultValue={values.timezone}
                                placeholder={'Africa/Abidjan'}
                                required
                            />
                            <datalist id="timezones">
                                {timezones.map((zone) => (
                                    <option key={zone} value={zone} />
                                ))}
                            </datalist>
                            <InputError message={errors.timezone} />
                        </div>
                        {showStatus && (
                            <div className="grid gap-2">
                                <Label htmlFor="status">{t('Status')}</Label>
                                <select
                                    id="status"
                                    name="status"
                                    defaultValue={values.status}
                                    className={selectClass}
                                >
                                    {statuses.map((status) => (
                                        <option key={status} value={status}>
                                            {status}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.status} />
                            </div>
                        )}
                    </div>

                    <fieldset className="grid gap-4 rounded-xl border p-4 sm:grid-cols-2">
                        <legend className="px-1 text-sm font-medium">
                            {t('Owner (optional)')}
                        </legend>
                        <p className="text-xs text-muted-foreground sm:col-span-2">
                            {t(
                                'An existing account is simply made owner. For a new e-mail, enter the name and a password: the account is created and the owner signs in with them (and can change the password in their settings). Share the password privately.',
                            )}
                        </p>
                        <div className="grid content-start gap-2 sm:col-span-2">
                            <Label htmlFor="owner_email">
                                {t('Owner e-mail')}
                            </Label>
                            <Input
                                id="owner_email"
                                name="owner_email"
                                type="email"
                                autoComplete="off"
                                defaultValue={values.owner_email ?? ''}
                            />
                            <InputError message={errors.owner_email} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="owner_first_name">
                                {t('First name (new account)')}
                            </Label>
                            <Input
                                id="owner_first_name"
                                name="owner_first_name"
                                autoComplete="off"
                            />
                            <InputError message={errors.owner_first_name} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="owner_last_name">
                                {t('Last name (new account)')}
                            </Label>
                            <Input
                                id="owner_last_name"
                                name="owner_last_name"
                                autoComplete="off"
                            />
                            <InputError message={errors.owner_last_name} />
                        </div>
                        <div className="grid content-start gap-2 sm:col-span-2">
                            <Label htmlFor="owner_password">
                                {t('Password (new account)')}
                            </Label>
                            <Input
                                id="owner_password"
                                name="owner_password"
                                type="password"
                                autoComplete="new-password"
                            />
                            <InputError message={errors.owner_password} />
                        </div>
                    </fieldset>

                    {!readOnly && (
                        <Button disabled={processing}>{submitLabel}</Button>
                    )}
                </fieldset>
            )}
        </Form>
    );
}
