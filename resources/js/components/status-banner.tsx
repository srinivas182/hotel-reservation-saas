import { usePage } from '@inertiajs/react';
import { t } from '@/lib/i18n';

type Notice = { id: number; level: string; title: string };

/** I: active incidents and maintenance, with a link to the status page. */
export function StatusBanner() {
    const { statusNotices = [] } = usePage().props as {
        statusNotices?: Notice[];
    };

    if (statusNotices.length === 0) {
        return null;
    }

    return (
        <div className="mx-4 mt-2 flex flex-col gap-1">
            {statusNotices.map((n) => (
                <p
                    key={n.id}
                    role="status"
                    className={`rounded-lg border px-3 py-2 text-sm ${n.level === 'maintenance' ? 'border-blue-600 text-blue-800 dark:text-blue-300' : 'border-amber-600 text-amber-900 dark:text-amber-200'}`}
                >
                    {n.level === 'maintenance'
                        ? t('Planned maintenance:')
                        : t('Service problem:')}{' '}
                    {n.title}{' '}
                    <a
                        href="/status"
                        target="_blank"
                        rel="noreferrer"
                        className="font-medium underline"
                    >
                        {t('See the status page')}
                    </a>
                </p>
            ))}
        </div>
    );
}
