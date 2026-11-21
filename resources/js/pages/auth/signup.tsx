import { Head, useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Check } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import SignupController from '@/actions/App/Domains/Onboarding/Http/Controllers/SignupController';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { login } from '@/routes';

type Props = {
    mode: string;
    code: string;
    countries: { code: string; name: string }[];
    legal: Record<string, string>;
};

type Field =
    | 'first_name'
    | 'last_name'
    | 'email'
    | 'phone'
    | 'password'
    | 'password_confirmation'
    | 'hotel_name'
    | 'country_code'
    | 'city'
    | 'rooms'
    | 'code'
    | 'accept_terms'
    | 'accept_dpa';

/** Which step holds each field, so a server error opens the right step. */
const STEP_OF: Record<Field, number> = {
    first_name: 0,
    last_name: 0,
    email: 0,
    phone: 0,
    password: 0,
    password_confirmation: 0,
    hotel_name: 1,
    country_code: 1,
    city: 1,
    rooms: 1,
    code: 1,
    accept_terms: 2,
    accept_dpa: 2,
};

const selectClass =
    'flex h-10 w-full rounded-lg border border-input bg-card px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-4 focus-visible:ring-brand/20';

/**
 * Sprint I: sign-up as three short steps (you, your hotel, agreements) with
 * a progress bar and a live preview of the greeting guests will see on
 * WhatsApp. One request at the end, as before; a server error opens the
 * step it belongs to.
 */
export default function Signup({ mode, code, countries, legal }: Props) {
    const [step, setStep] = useState(0);
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
    const errors = form.errors as Partial<Record<Field, string>>;

    // A server error sends the person back to the step that needs fixing.
    useEffect(() => {
        const steps = (Object.keys(errors) as Field[])
            .map((key) => STEP_OF[key])
            .filter((s) => s !== undefined);
        if (steps.length > 0) {
            setStep(Math.min(...steps));
        }
    }, [errors]);

    if (mode === 'closed') {
        return (
            <>
                <Head title={t('Create your hotel account')} />
                <p className="text-sm text-muted-foreground">
                    {t('Sign-ups are closed for now. Please contact us.')}
                </p>
            </>
        );
    }

    const steps = [t('You'), t('Your hotel'), t('Agreements')];
    const ready = [
        form.data.first_name.trim() !== '' &&
            form.data.email.includes('@') &&
            form.data.password.length >= 8 &&
            form.data.password === form.data.password_confirmation,
        form.data.hotel_name.trim() !== '' &&
            form.data.city.trim() !== '' &&
            form.data.rooms > 0,
        form.data.accept_terms && form.data.accept_dpa,
    ];

    const input = (
        key: Exclude<
            Field,
            'rooms' | 'country_code' | 'accept_terms' | 'accept_dpa'
        >,
        label: string,
        type = 'text',
        autoComplete?: string,
    ) => (
        <div className="grid gap-1.5">
            <Label htmlFor={key}>{label}</Label>
            <Input
                id={key}
                type={type}
                autoComplete={autoComplete}
                value={form.data[key]}
                aria-invalid={errors[key] ? true : undefined}
                onChange={(e) => form.setData(key, e.target.value)}
            />
            <InputError message={errors[key]} />
        </div>
    );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (step < 2) {
            if (ready[step]) {
                setStep(step + 1);
            }

            return;
        }
        form.post(SignupController.store.url());
    };

    return (
        <>
            <Head title={t('Create your hotel account')} />

            <ol className="grid grid-cols-3 gap-2" aria-label={t('Steps')}>
                {steps.map((label, i) => (
                    <li key={label}>
                        <button
                            type="button"
                            onClick={() => i < step && setStep(i)}
                            disabled={i > step}
                            aria-current={i === step ? 'step' : undefined}
                            className="w-full text-left"
                        >
                            <span
                                className={cn(
                                    'block h-1.5 rounded-full transition-colors duration-300',
                                    i <= step ? 'bg-brand' : 'bg-border',
                                )}
                            />
                            <span
                                className={cn(
                                    'mt-2 flex items-center gap-1.5 text-xs font-semibold',
                                    i === step
                                        ? 'text-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {i < step ? (
                                    <Check className="size-3.5 text-brand" />
                                ) : (
                                    <span className="tabular-nums">
                                        {i + 1}.
                                    </span>
                                )}
                                {label}
                            </span>
                        </button>
                    </li>
                ))}
            </ol>

            <form onSubmit={submit} className="grid gap-5" noValidate>
                {step === 0 && (
                    <Panel key="you">
                        <div className="grid gap-4 sm:grid-cols-2">
                            {input(
                                'first_name',
                                t('First name'),
                                'text',
                                'given-name',
                            )}
                            {input(
                                'last_name',
                                t('Last name'),
                                'text',
                                'family-name',
                            )}
                        </div>
                        {input('email', t('Email'), 'email', 'email')}
                        {input('phone', t('Phone (WhatsApp)'), 'tel', 'tel')}
                        <div className="grid gap-4 sm:grid-cols-2">
                            {input(
                                'password',
                                t('Password'),
                                'password',
                                'new-password',
                            )}
                            {input(
                                'password_confirmation',
                                t('Confirm password'),
                                'password',
                                'new-password',
                            )}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'At least 8 characters. You will set up two-step login after your first sign-in.',
                            )}
                        </p>
                    </Panel>
                )}

                {step === 1 && (
                    <Panel key="hotel">
                        {input('hotel_name', t('Hotel name'))}
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="country_code">
                                    {t('Country')}
                                </Label>
                                <select
                                    id="country_code"
                                    className={selectClass}
                                    value={form.data.country_code}
                                    onChange={(e) =>
                                        form.setData(
                                            'country_code',
                                            e.target.value,
                                        )
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
                            {input('city', t('City'))}
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="rooms">
                                    {t('Number of rooms')}
                                </Label>
                                <Input
                                    id="rooms"
                                    type="number"
                                    min={1}
                                    value={form.data.rooms}
                                    onChange={(e) =>
                                        form.setData(
                                            'rooms',
                                            Number(e.target.value),
                                        )
                                    }
                                />
                                <InputError message={errors.rooms} />
                            </div>
                            {(mode === 'code' || code !== '') &&
                                input('code', t('Invitation code'))}
                        </div>
                        <GreetingPreview
                            hotel={form.data.hotel_name}
                            city={form.data.city}
                        />
                    </Panel>
                )}

                {step === 2 && (
                    <Panel key="agreements">
                        <dl className="grid gap-2 rounded-xl border bg-muted/50 p-4 text-sm">
                            <Summary label={t('Account')}>
                                {form.data.first_name} {form.data.last_name} ·{' '}
                                {form.data.email}
                            </Summary>
                            <Summary label={t('Hotel')}>
                                {form.data.hotel_name} · {form.data.city} ·{' '}
                                {t(':count rooms', {
                                    count: String(form.data.rooms),
                                })}
                            </Summary>
                        </dl>
                        <Agreement
                            checked={form.data.accept_terms}
                            onChange={(v) => form.setData('accept_terms', v)}
                            error={errors.accept_terms}
                        >
                            {t('I accept the')}{' '}
                            <a
                                href={legal.terms}
                                target="_blank"
                                rel="noreferrer"
                                className="font-medium underline"
                            >
                                {t('terms of service')}
                            </a>{' '}
                            {t('and the')}{' '}
                            <a
                                href={legal.privacy}
                                target="_blank"
                                rel="noreferrer"
                                className="font-medium underline"
                            >
                                {t('privacy notice')}
                            </a>
                            .
                        </Agreement>
                        <Agreement
                            checked={form.data.accept_dpa}
                            onChange={(v) => form.setData('accept_dpa', v)}
                            error={errors.accept_dpa}
                        >
                            {t('I accept the')}{' '}
                            <a
                                href={legal.dpa}
                                target="_blank"
                                rel="noreferrer"
                                className="font-medium underline"
                            >
                                {t('data processing agreement')}
                            </a>{' '}
                            {t('for my guests’ data.')}
                        </Agreement>
                    </Panel>
                )}

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

                <div className="flex gap-3">
                    {step > 0 && (
                        <Button
                            type="button"
                            variant="outline"
                            size="lg"
                            onClick={() => setStep(step - 1)}
                        >
                            <ArrowLeft />
                            {t('Back')}
                        </Button>
                    )}
                    <Button
                        size="lg"
                        className="flex-1"
                        disabled={!ready[step] || form.processing}
                        data-test="signup-next"
                    >
                        {step < 2 ? t('Continue') : t('Create my account')}
                        {step < 2 && <ArrowRight />}
                    </Button>
                </div>
            </form>

            <p className="text-center text-sm text-muted-foreground">
                {t('Already have an account?')}{' '}
                <TextLink href={login()}>{t('Log in')}</TextLink>
            </p>
        </>
    );
}

function Panel({ children }: { children: ReactNode }) {
    return <div className="grid animate-enter gap-4">{children}</div>;
}

function Summary({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex gap-3">
            <dt className="w-20 shrink-0 text-muted-foreground">{label}</dt>
            <dd className="font-medium">{children}</dd>
        </div>
    );
}

function Agreement({
    checked,
    onChange,
    error,
    children,
}: {
    checked: boolean;
    onChange: (value: boolean) => void;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div>
            <label
                className={cn(
                    'flex cursor-pointer items-start gap-3 rounded-xl border bg-card p-4 text-sm transition-colors',
                    checked && 'border-brand bg-brand-soft/60',
                )}
            >
                <input
                    type="checkbox"
                    className="mt-0.5 size-4 accent-[var(--brand)]"
                    checked={checked}
                    onChange={(e) => onChange(e.target.checked)}
                />
                <span>{children}</span>
            </label>
            <InputError message={error} />
        </div>
    );
}

/** What a guest will see when they first write to the hotel. */
function GreetingPreview({ hotel, city }: { hotel: string; city: string }) {
    const name = hotel.trim() || t('your hotel');

    return (
        <figure className="rounded-2xl bg-[#e9e3da] p-4 dark:bg-[#1b1a17]">
            <figcaption className="mb-3 text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                {t('What guests will see on WhatsApp')}
            </figcaption>
            <p className="max-w-[80%] rounded-2xl rounded-tl-md bg-white px-3 py-2 text-[13px] shadow-xs dark:bg-[#2a2926]">
                {t('Hello, do you have a room this weekend?')}
            </p>
            <p className="mt-2 ml-auto max-w-[85%] rounded-2xl rounded-tr-md bg-[#d9fdd3] px-3 py-2 text-[13px] shadow-xs dark:bg-[#144d37]">
                {t(
                    'Hello and welcome to :hotel:city! I am the hotel’s assistant: tell me your dates and I will check our rooms for you.',
                    {
                        hotel: name,
                        city: city.trim() ? ` (${city.trim()})` : '',
                    },
                )}
            </p>
        </figure>
    );
}

Signup.layout = {
    title: 'Open your hotel account',
    description: 'Three short steps, 14 days free, no card needed.',
};
