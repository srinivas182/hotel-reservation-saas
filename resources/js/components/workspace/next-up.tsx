import { Link } from '@inertiajs/react';
import { ArrowRight, CircleCheck, TriangleAlert } from 'lucide-react';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type NextUpItem = {
    kind: string;
    tone: 'urgent' | 'normal' | 'calm';
    text: string;
    href: string;
    more: number;
};

/** Addition 1: the single most urgent thing, at the top of Today. */
export function NextUpCard({ item, big }: { item: NextUpItem; big: boolean }) {
    const Icon = item.tone === 'calm' ? CircleCheck : TriangleAlert;

    return (
        <Link
            href={item.href}
            data-tour="next-up"
            className={cn(
                'flex items-center gap-3 rounded-xl border-2 px-4 py-3 hover:bg-muted/50',
                item.tone === 'urgent' && 'border-destructive',
                item.tone === 'normal' && 'border-amber-500',
                item.tone === 'calm' && 'border-emerald-600',
                big && 'py-5 text-lg',
            )}
        >
            <Icon
                className={cn('shrink-0', big ? 'size-7' : 'size-5')}
                aria-hidden
            />
            <span className="flex flex-1 flex-col">
                <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t('Next up')}
                </span>
                <span className="font-medium">{item.text}</span>
                {item.more > 0 && (
                    <span className="text-sm text-muted-foreground">
                        {t('and :count more', { count: item.more })}
                    </span>
                )}
            </span>
            {item.kind !== 'clear' && (
                <ArrowRight className="size-5 shrink-0" aria-hidden />
            )}
        </Link>
    );
}
