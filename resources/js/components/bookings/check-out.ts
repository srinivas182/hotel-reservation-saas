import { router } from '@inertiajs/react';
import BookingActionController from '@/actions/App/Domains/Bookings/Http/Controllers/BookingActionController';
import { t } from '@/lib/i18n';

/**
 * Check a guest out. When the room bill still has a balance, the server
 * refuses; staff can then give a reason to check out anyway (audited).
 */
export function checkOut(bookingId: string, confirmMessage?: string): void {
    if (confirmMessage && !window.confirm(confirmMessage)) {
        return;
    }

    const url = BookingActionController.checkOut.url(bookingId);
    router.post(
        url,
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                if (errors.balance) {
                    const reason = window.prompt(
                        `${errors.balance}\n\n${t('Reason to check out with a balance (or cancel and record the payment first):')}`,
                    );

                    if (reason && reason.trim() !== '') {
                        router.post(
                            url,
                            { balance_reason: reason },
                            { preserveScroll: true },
                        );
                    }
                }
            },
        },
    );
}
