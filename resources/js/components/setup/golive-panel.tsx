import { router } from '@inertiajs/react';
import SetupController from '@/actions/App/Domains/Onboarding/Http/Controllers/SetupController';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

export type GoLiveState = {
    requested_at: string | null;
    decision: string | null;
    reason: string | null;
    blockers: string[];
    approval_required: boolean;
};

export function GoLivePanel({ golive }: { golive: GoLiveState }) {
    if (golive.decision === 'approved') {
        return (
            <p className="text-emerald-700 dark:text-emerald-400">
                {t(
                    'You are live. The assistant prepares replies in supervised mode: check and send them from the inbox.',
                )}
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-2">
            {golive.decision === 'refused' && (
                <p className="rounded-lg border border-destructive px-3 py-2 text-destructive">
                    {t('Your request needs changes:')} {golive.reason}
                </p>
            )}
            {golive.requested_at && golive.decision === null ? (
                <p>
                    {t(
                        'Request sent on :date. The platform team is reviewing your hotel.',
                        { date: golive.requested_at },
                    )}
                </p>
            ) : golive.blockers.length > 0 ? (
                <div>
                    <p className="font-medium">
                        {t('Still to do before going live:')}
                    </p>
                    <ul className="list-inside list-disc">
                        {golive.blockers.map((b) => (
                            <li key={b}>{b}</li>
                        ))}
                    </ul>
                </div>
            ) : (
                <div className="flex flex-col gap-2">
                    <p>
                        {golive.approval_required
                            ? t(
                                  'Everything is ready. Send your request: the platform team checks your hotel, usually within a day.',
                              )
                            : t(
                                  'Everything is ready. The assistant will start in supervised mode.',
                              )}
                    </p>
                    <div>
                        <Button
                            onClick={() =>
                                router.post(
                                    SetupController.requestGoLive.url(),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {t('Ask to go live')}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
