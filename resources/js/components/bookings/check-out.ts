import { router } from '@inertiajs/react';
import BookingActionController from '@/actions/App/Domains/Bookings/Http/Controllers/BookingActionController';
import { askConfirm, askText } from '@/lib/ask';
import { t } from '@/lib/i18n';

/**
 * Check a guest out. When the room bill still has a balance, the server
 * refuses; staff can then give a reason to check out anyway (audited).
 */
export async function checkOut(
    bookingId: string,
    confirmMessage?: string,
): Promise<void> {
    if (confirmMessage && !(await askConfirm(confirmMessage))) {
        return;
    }

    const url = BookingActionController.checkOut.url(bookingId);
    router.post(
        url,
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                if (!errors.balance) {
                    return;
                }
                void askText({
                    title: t('The bill still has a balance'),
                    description: `${errors.balance} ${t('Reason to check out with a balance (or cancel and record the payment first):')}`,
                    label: t('Reason'),
                    required: true,
                    confirmLabel: t('Check out anyway'),
                }).then((reason) => {
                    if (reason) {
                        router.post(
                            url,
                            { balance_reason: reason },
                            { preserveScroll: true },
                        );
                    }
                });
            },
        },
    );
}
