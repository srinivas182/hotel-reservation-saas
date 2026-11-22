import { Head } from '@inertiajs/react';
import HotelController from '@/actions/App/Domains/Platform/Http/Controllers/HotelController';
import { PageBody, PageHeader } from '@/components/page';
import { HotelForm } from '@/components/platform/hotel-form';
import { t } from '@/lib/i18n';

type Props = {
    statuses: string[];
    locales: string[];
    timezones: string[];
    defaultLocale: string;
};

export default function HotelsCreate({
    statuses,
    locales,
    timezones,
    defaultLocale,
}: Props) {
    return (
        <>
            <Head title={t('New hotel')} />
            <PageBody>
                <PageHeader
                    title={t('New hotel')}
                    description={t(
                        'Hotels start on a trial. You can change the status after creating it.',
                    )}
                />
                <HotelForm
                    action={HotelController.store.form()}
                    values={{
                        default_locale: defaultLocale,
                        country_code: 'CI',
                        currency: 'XOF',
                        timezone: 'Africa/Abidjan',
                    }}
                    statuses={statuses}
                    locales={locales}
                    timezones={timezones}
                    showStatus={false}
                    submitLabel={t('Create hotel')}
                />
            </PageBody>
        </>
    );
}

HotelsCreate.layout = {
    breadcrumbs: [
        { title: 'Hotels', href: HotelController.index() },
        { title: 'New hotel', href: HotelController.create() },
    ],
};
