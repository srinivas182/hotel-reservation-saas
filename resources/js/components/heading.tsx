import { t } from '@/lib/i18n';
export default function Heading({
    title,
    description,
    variant = 'default',
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
}) {
    return (
        <header className={variant === 'small' ? '' : 'mb-8 space-y-0.5'}>
            <h2
                className={
                    variant === 'small'
                        ? 'mb-0.5 text-base font-medium'
                        : 'text-xl font-semibold tracking-tight'
                }
            >
                {t(title)}
            </h2>
            {description && (
                <p className="text-sm text-muted-foreground">
                    {t(description)}
                </p>
            )}
        </header>
    );
}
