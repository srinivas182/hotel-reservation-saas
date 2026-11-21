import { useForm } from '@inertiajs/react';
import { Check } from 'lucide-react';
import type { FormEvent } from 'react';
import HotelSettingsController from '@/actions/App/Domains/Hotel/Http/Controllers/HotelSettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const PRESETS = [
    '#4f46e5',
    '#0f766e',
    '#0e7490',
    '#1d4ed8',
    '#7c3aed',
    '#be185d',
    '#b45309',
    '#15803d',
];

/**
 * Sprint I: the hotel's own colour for its back office. Text stays readable
 * whatever the colour: the server adjusts the shades (BrandPalette).
 */
export function AppearanceForm({
    value,
    platformColor,
    kitColor,
    canManage,
}: {
    value: string | null;
    platformColor: string;
    kitColor: string | null;
    canManage: boolean;
}) {
    const form = useForm<{ app_color: string | null }>({ app_color: value });
    const current = form.data.app_color ?? platformColor;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(HotelSettingsController.appearance.url(), {
            preserveScroll: true,
        });
    };

    const Swatch = ({ color, label }: { color: string; label: string }) => (
        <button
            type="button"
            disabled={!canManage}
            onClick={() => form.setData('app_color', color)}
            aria-label={label}
            aria-pressed={current.toLowerCase() === color.toLowerCase()}
            title={label}
            className="flex size-9 items-center justify-center rounded-full ring-offset-2 ring-offset-background transition-transform hover:scale-105 aria-pressed:ring-2 aria-pressed:ring-foreground"
            style={{ background: color }}
        >
            {current.toLowerCase() === color.toLowerCase() && (
                <Check className="size-4 text-white drop-shadow" />
            )}
        </button>
    );

    return (
        <form onSubmit={submit} className="grid gap-4">
            <Heading
                variant="small"
                title={t('Colour of your back office')}
                description={t(
                    'The menu, buttons and highlights take this colour for everyone at your hotel. Text stays readable whatever you choose.',
                )}
            />
            <div className="flex flex-wrap items-center gap-3">
                {PRESETS.map((c) => (
                    <Swatch key={c} color={c} label={c} />
                ))}
                {kitColor && !PRESETS.includes(kitColor.toLowerCase()) && (
                    <Swatch color={kitColor} label={t('WhatsApp kit colour')} />
                )}
                <label
                    className={cn(
                        'flex h-9 items-center gap-2 rounded-full border bg-card px-3 text-sm',
                        !canManage && 'opacity-50',
                    )}
                >
                    <input
                        type="color"
                        value={current}
                        disabled={!canManage}
                        onChange={(e) =>
                            form.setData('app_color', e.target.value)
                        }
                        className="size-5 cursor-pointer rounded-full border-0 bg-transparent p-0"
                    />
                    {t('Other colour')}
                </label>
            </div>
            <InputError message={form.errors.app_color} />
            {canManage && (
                <div className="flex flex-wrap gap-3">
                    <Button disabled={form.processing || !form.isDirty}>
                        {t('Save colour')}
                    </Button>
                    {form.data.app_color !== null && (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => form.setData('app_color', null)}
                        >
                            {t('Use the standard colour')}
                        </Button>
                    )}
                </div>
            )}
        </form>
    );
}
