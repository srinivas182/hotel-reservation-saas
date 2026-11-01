import { Head } from '@inertiajs/react';
import { t } from '@/lib/i18n';

export default function ReplyToConfirmed({
    confirmed,
}: {
    confirmed: boolean;
}) {
    return (
        <>
            <Head title={t('Reply address')} />
            <p className="text-center text-sm text-muted-foreground">
                {confirmed
                    ? t(
                          'Thank you. Guest replies will now be sent to this address.',
                      )
                    : t(
                          'This confirmation link is not valid or has expired. Ask the hotel to send a new one.',
                      )}
            </p>
        </>
    );
}

ReplyToConfirmed.layout = {
    title: 'Reply address',
    description: '',
};
