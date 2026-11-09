import { Head } from '@inertiajs/react';
import { t } from '@/lib/i18n';

export default function ProductEmailsOff() {
    return (
        <>
            <Head title={t('Product emails stopped')} />
            <p className="text-center text-sm text-muted-foreground">
                {t('You can turn them back on at any time in “What’s new”.')}
            </p>
        </>
    );
}

ProductEmailsOff.layout = {
    title: 'Product emails stopped',
    description: 'You will no longer receive “What’s new” and ideas emails.',
};
