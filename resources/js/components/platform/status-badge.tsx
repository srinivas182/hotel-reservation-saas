import { Badge } from '@/components/ui/badge';

const variants: Record<
    string,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    active: 'default',
    trial: 'secondary',
    suspended: 'destructive',
    closed: 'outline',
};

export function StatusBadge({ status }: { status: string }) {
    return (
        <Badge variant={variants[status] ?? 'outline'} className="capitalize">
            {status}
        </Badge>
    );
}
