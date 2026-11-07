import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import SignupController from '@/actions/App/Domains/Onboarding/Http/Controllers/SignupController';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { login } from '@/routes';

type Props = {
    mode: string;
    code: string;
    countries: { code: string; name: string }[];
    legal: Record<string, string>;
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

export default function Signup({ mode, code, countries, legal }: Props) {
    const form = useForm({
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        password: '',
        password_confirmation: '',
        hotel_name: '',
        country_code:
            countries.find((c) => c.code === 'CI')?.code ??
            countries[0]?.code ??
            '',
        city: '',
        rooms: 10,
        code,
        website: '',
        accept_terms: false,
        accept_dpa: false,
    });
    const errors = form.errors as Record<string, string | undefined>;

    if (mode === 'closed') {
        return (
            <>
                <Head title={t('Create your hotel account')} />
                <p className="text-center text-sm text-muted-foreground">
                    {t('Sign-ups are closed for now. Please contact us.')}
                </p>
            </>
        );
    }

    const field = (
        key:
            | 'first_name'
            | 'last_name'
            | 'email'
            | 'phone'
            | 'hotel_name'
            | 'city',
        label: string,
        type = 'text',
        autoComplete?: string,
    ) => (
        <div className="grid gap-1">
            <Label htmlFor={key}>{label}</Label>
            <Input
                id={key}
                type={type}
                autoComplete={autoComplete}
                value={form.data[key]}
                onChange={(e) => form.setData(key, e.target.value)}
            />
            <InputError message={errors[key]} />
        </div>
    );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(SignupController.store.url());
    };

    return (
        <>
            <Head title={t('Create your hotel account')} />
            <form onSubmit={submit} className="grid gap-4">
                <div className="grid gap-3 sm:grid-cols-2">
                    {field('first_name', t('First name'), 'text', 'given-name')}
                    {field('last_name', t('Last name'), 'text', 'family-name')}
                    {field('email', t('Email'), 'email', 'email')}
                    {field('phone', t('Phone (WhatsApp)'), 'tel', 'tel')}
                    <div className="grid gap-1">
                        <Label htmlFor="password">{t('Password')}</Label>
                        <Input
                            id="password"
                            type="password"
                            autoComplete="new-password"
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData('password', e.target.value)
                            }
                        />
                        <InputError message={errors.password} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="password_confirmation">
                            {t('Confirm password')}
                        </Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            autoComplete="new-password"
                            value={form.data.password_confirmation}
                            onChange={(e) =>
                                form.setData(
                                    'password_confirmation',
                                    e.target.value,
                                )
                            }
                        />
                    </div>
                </div>
                <div className="grid gap-3 border-t pt-4 sm:grid-cols-2">
                    {field('hotel_name', t('Hotel name'))}
                    <div className="grid gap-1">
                        <Label htmlFor="country_code">{t('Country')}</Label>
                        <select
                            id="country_code"
                            className={selectClass}
                            value={form.data.country_code}
                            onChange={(e) =>
                                form.setData('country_code', e.target.value)
                            }
                        >
                            {countries.map((c) => (
                                <option key={c.code} value={c.code}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.country_code} />
                    </div>
                    {field('city', t('City'))}
                    <div className="grid gap-1">
                        <Label htmlFor="rooms">{t('Number of rooms')}</Label>
                        <Input
                            id="rooms"
                            type="number"
                            min={1}
                            value={form.data.rooms}
                            onChange={(e) =>
                                form.setData('rooms', Number(e.target.value))
                            }
                        />
                        <InputError message={errors.rooms} />
                    </div>
                    {(mode === 'code' || code !== '') && (
                        <div className="grid gap-1">
                            <Label htmlFor="code">{t('Invitation code')}</Label>
                            <Input
                                id="code"
                                value={form.data.code}
                                onChange={(e) =>
                                    form.setData('code', e.target.value)
                                }
                            />
                            <InputError message={errors.code} />
                        </div>
                    )}
                </div>
                {/* Spam trap: hidden from people, filled by bots. */}
                <input
                    type="text"
                    name="website"
                    tabIndex={-1}
                    autoComplete="off"
                    aria-hidden="true"
                    className="hidden"
                    value={form.data.website}
                    onChange={(e) => form.setData('website', e.target.value)}
                />
                <label className="flex items-start gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="mt-1"
                        checked={form.data.accept_terms}
                        onChange={(e) =>
                            form.setData('accept_terms', e.target.checked)
                        }
                    />
                    <span>
                        {t('I accept the')}{' '}
                        <a
                            href={legal.terms}
                            target="_blank"
                            rel="noreferrer"
                            className="underline"
                        >
                            {t('terms of service')}
                        </a>{' '}
                        {t('and the')}{' '}
                        <a
                            href={legal.privacy}
                            target="_blank"
                            rel="noreferrer"
                            className="underline"
                        >
                            {t('privacy notice')}
                        </a>
                        .
                    </span>
                </label>
                <InputError message={errors.accept_terms} />
                <label className="flex items-start gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="mt-1"
                        checked={form.data.accept_dpa}
                        onChange={(e) =>
                            form.setData('accept_dpa', e.target.checked)
                        }
                    />
                    <span>
                        {t('I accept the')}{' '}
                        <a
                            href={legal.dpa}
                            target="_blank"
                            rel="noreferrer"
                            className="underline"
                        >
                            {t('data processing agreement')}
                        </a>{' '}
                        {t('for my guests’ data.')}
                    </span>
                </label>
                <InputError message={errors.accept_dpa} />
                <Button disabled={form.processing}>
                    {t('Create my account')}
                </Button>
                <p className="text-center text-sm text-muted-foreground">
                    {t('Already have an account?')}{' '}
                    <TextLink href={login()}>{t('Log in')}</TextLink>
                </p>
            </form>
        </>
    );
}

Signup.layout = {
    title: 'Create your hotel account',
    description:
        '14 days free, no card needed. You will confirm your email next.',
};
