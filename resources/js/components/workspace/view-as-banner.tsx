import { Eye } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { stopViewAs, useWorkspace } from '@/lib/workspace';

/** Addition 6: the owner sees what a role sees, and can leave the preview. */
export function ViewAsBanner() {
    const workspace = useWorkspace();

    if (!workspace?.viewAs) {
        return null;
    }

    return (
        <div
            role="status"
            className="mx-4 mt-2 flex flex-wrap items-center gap-3 rounded-lg border-2 border-primary bg-card px-3 py-2 text-sm"
        >
            <Eye className="size-4" aria-hidden />
            <span className="flex-1">
                {t(
                    'You are seeing the dashboard as :role. Your own rights still apply to what you do.',
                    { role: workspace.viewAs.name },
                )}
            </span>
            <Button size="sm" variant="outline" onClick={() => stopViewAs()}>
                {t('Back to my view')}
            </Button>
        </div>
    );
}
