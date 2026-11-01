import { Badge } from '@/components/ui/badge';

const variants: Record<
    string,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    tentative: 'outline',
    confirmed: 'default',
    checked_in: 'secondary',
    checked_out: 'outline',
    no_show: 'destructive',
    cancelled: 'destructive',
};

export function BookingStatusBadge({
    status,
    label,
}: {
    status: string;
    label: string;
}) {
    return <Badge variant={variants[status] ?? 'outline'}>{label}</Badge>;
}
