import { Head } from '@inertiajs/react';
import UsageController from '@/actions/App/Domains/Messaging/Http/Controllers/UsageController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { t } from '@/lib/i18n';

type CategoryRow = {
    category: string;
    label: string;
    counted: boolean;
    sent: number;
    failed: number;
};

type Props = {
    categories: CategoryRow[];
    days: { date: string; sent: number }[];
    limit: number | null;
    month: string;
};

export default function Usage({ categories, days, limit, month }: Props) {
    const counted = categories
        .filter((row) => row.counted)
        .reduce((sum, row) => sum + row.sent, 0);
    const failed = categories.reduce((sum, row) => sum + row.failed, 0);
    const peak = Math.max(1, ...days.map((day) => day.sent));

    return (
        <>
            <Head title={t('Usage')} />
            <div className="flex max-w-4xl flex-col gap-8 p-4">
                <Heading
                    title={t('Usage')}
                    description={t('Emails sent by your hotel in :month.', {
                        month,
                    })}
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-xl border p-4">
                        <div className="text-sm text-muted-foreground">
                            {t('Counted emails')}
                        </div>
                        <div className="text-2xl font-semibold">{counted}</div>
                    </div>
                    <div className="rounded-xl border p-4">
                        <div className="text-sm text-muted-foreground">
                            {t('Email limit')}
                        </div>
                        <div className="text-2xl font-semibold">
                            {limit === null ? t('Unlimited (pilot)') : limit}
                        </div>
                    </div>
                    <div className="rounded-xl border p-4">
                        <div className="text-sm text-muted-foreground">
                            {t('Failed this month')}
                        </div>
                        <div className="text-2xl font-semibold">{failed}</div>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">
                                    {t('Type')}
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    {t('Sent')}
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    {t('Failed')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {categories.map((row) => (
                                <tr key={row.category} className="border-t">
                                    <td className="px-4 py-3">
                                        {row.label}{' '}
                                        {!row.counted && (
                                            <Badge variant="outline">
                                                {t('Not counted')}
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {row.sent}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {row.failed}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <section className="flex flex-col gap-3">
                    <Heading
                        variant="small"
                        title={t('Last 30 days')}
                        description={t('Emails sent per day')}
                    />
                    <div
                        className="flex h-32 items-end gap-1 rounded-xl border p-3"
                        role="img"
                        aria-label={t('Emails sent per day')}
                    >
                        {days.map((day) => (
                            <div
                                key={day.date}
                                title={`${day.date}: ${day.sent}`}
                                className="flex-1 rounded-sm bg-primary/70"
                                style={{
                                    height: `${Math.max(2, (day.sent / peak) * 100)}%`,
                                    opacity: day.sent === 0 ? 0.15 : 1,
                                }}
                            />
                        ))}
                    </div>
                </section>
            </div>
        </>
    );
}

Usage.layout = {
    breadcrumbs: [{ title: 'Usage', href: UsageController() }],
};
