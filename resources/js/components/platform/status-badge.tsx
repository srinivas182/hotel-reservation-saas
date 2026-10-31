import { Badge } from '@/components/ui/badge';
import { t } from '@/lib/i18n';

const variants: Record<
    string,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    active: 'default',
    trial: 'secondary',
    suspended: 'destructive',
    closed: 'outline',
};

const labels: Record<string, string> = {
    active: 'Active',
    trial: 'Trial',
    suspended: 'Suspended',
    closed: 'Closed',
};

export function StatusBadge({ status }: { status: string }) {
    return (
        <Badge variant={variants[status] ?? 'outline'}>
            {t(labels[status] ?? status)}
        </Badge>
    );
}
