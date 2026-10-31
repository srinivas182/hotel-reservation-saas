import { Head } from '@inertiajs/react';
import HotelController from '@/actions/App/Domains/Platform/Http/Controllers/HotelController';
import Heading from '@/components/heading';
import { HotelForm } from '@/components/platform/hotel-form';
import { t } from '@/lib/i18n';

type Props = {
    statuses: string[];
    locales: string[];
    timezones: string[];
};

export default function HotelsCreate({ statuses, locales, timezones }: Props) {
    return (
        <>
            <Head title={t('New hotel')} />
            <div className="flex max-w-3xl flex-col gap-6 p-4">
                <Heading
                    title={t('New hotel')}
                    description={t(
                        'Hotels start on a trial. You can change the status after creating it.',
                    )}
                />
                <HotelForm
                    action={HotelController.store.form()}
                    values={{
                        default_locale: 'fr',
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
            </div>
        </>
    );
}

HotelsCreate.layout = {
    breadcrumbs: [
        { title: 'Hotels', href: HotelController.index() },
        { title: 'New hotel', href: HotelController.create() },
    ],
};
