import { CircleHelp } from 'lucide-react';
import { useState } from 'react';
import { t } from '@/lib/i18n';
import { openHelp, useWorkspace } from '@/lib/workspace';

/**
 * Addition 4: a "?" next to a section of a simple screen. It opens two or
 * three plain lines in place, with a link to the full help.
 */
export function ScreenTip({ title, text }: { title: string; text: string }) {
    const workspace = useWorkspace();
    const [open, setOpen] = useState(false);

    if (!workspace?.simple) {
        return null;
    }

    return (
        <span className="inline-flex flex-col">
            <button
                type="button"
                aria-expanded={open}
                aria-label={t('Help: :title', { title })}
                onClick={() => setOpen(!open)}
                className="inline-flex size-8 items-center justify-center rounded-full text-muted-foreground hover:bg-muted"
            >
                <CircleHelp className="size-5" aria-hidden />
            </button>
            {open && (
                <span
                    role="note"
                    className="mt-1 max-w-sm rounded-lg border bg-card p-3 text-sm font-normal"
                >
                    {text}{' '}
                    <button
                        type="button"
                        className="underline"
                        onClick={() => openHelp()}
                    >
                        {t('More help')}
                    </button>
                </span>
            )}
        </span>
    );
}
