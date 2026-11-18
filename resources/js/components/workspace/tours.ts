import { t } from '@/lib/i18n';

/**
 * Guided tours (Sprint E). A step points at an element marked
 * data-tour="…"; a step whose element is not on screen (another role, a
 * phone) is skipped. Keep the keys in step with App\Domains\Workspace\Tours.
 */
export type TourStep = {
    target?: string;
    title: string;
    text: string;
    permission?: string;
};

export type Tour = {
    key: string;
    /** Feature tours: shown once to people who joined before this day. */
    since?: string;
    permission?: string;
    simpleOnly?: boolean;
    steps: () => TourStep[];
};

export const TOURS: Tour[] = [
    {
        key: 'welcome',
        steps: () => [
            {
                title: t('Welcome'),
                text: t(
                    'One minute to find your way. You can skip this tour and replay it from your menu at any time.',
                ),
            },
            {
                target: 'next-up',
                permission: 'bookings.view',
                title: t('What to do next'),
                text: t(
                    'This card always shows the most urgent thing: a request to answer, a guest waiting, a room to choose.',
                ),
            },
            {
                target: 'requests',
                permission: 'booking_requests.decide',
                title: t('WhatsApp requests'),
                text: t(
                    'Guests ask on WhatsApp, the assistant prepares the booking. You accept or decline here, or from the WhatsApp alert.',
                ),
            },
            {
                target: 'tab-inbox',
                permission: 'conversations.view',
                title: t('Messages'),
                text: t(
                    'All WhatsApp conversations with guests. A red number means someone is waiting for you.',
                ),
            },
            {
                target: 'nav-inbox',
                permission: 'conversations.view',
                title: t('Messages'),
                text: t(
                    'All WhatsApp conversations with guests. A red number means someone is waiting for you.',
                ),
            },
            {
                target: 'checklists',
                title: t('Shift checklist'),
                text: t(
                    'Tick each task when it is done. The owner sees it in the morning summary.',
                ),
            },
            {
                target: 'nav-alerts',
                title: t('Alerts on your phone'),
                text: t(
                    'In My alerts, turn on notifications on this phone: new requests arrive on your lock screen, for free.',
                ),
            },
            {
                target: 'help',
                title: t('Help'),
                text: t(
                    'Help for the screen you are on, and a way to ask our support team.',
                ),
            },
        ],
    },
    {
        key: 'whatsapp-buttons',
        since: '2026-11-18',
        permission: 'booking_requests.decide',
        steps: () => [
            {
                title: t('New: answer requests from WhatsApp'),
                text: t(
                    'Request alerts now have Accept and Decline buttons. Each decision waits one minute so you can undo it.',
                ),
            },
            {
                target: 'nav-alerts',
                title: t('Your choice'),
                text: t('Turn it on or off for yourself in My alerts.'),
            },
        ],
    },
    {
        key: 'phone-app',
        since: '2026-11-19',
        steps: () => [
            {
                title: t('New: the app on your phone'),
                text: t(
                    'Put the dashboard on your home screen. Notifications are free and come before WhatsApp.',
                ),
            },
            {
                target: 'nav-alerts',
                title: t('Turn on notifications'),
                text: t(
                    'In My alerts, tap “Turn on notifications on this phone”, then send a test.',
                ),
            },
        ],
    },
    {
        key: 'simple-mode',
        since: '2026-11-19',
        simpleOnly: true,
        steps: () => [
            {
                title: t('New: a simpler dashboard'),
                text: t(
                    'Today, Messages and Bookings, with big buttons. You can switch to the full view from your menu.',
                ),
            },
            {
                target: 'tab-today',
                title: t('Three tabs'),
                text: t(
                    'Today, Messages and Bookings are always at the bottom.',
                ),
            },
        ],
    },
];

/** The tour to show now, if any: welcome first, then one feature tour at a time. */
export function tourToShow(options: {
    done: string[];
    memberSince: string | null;
    simple: boolean;
    permissions: string[];
}): Tour | null {
    const allowed = (tour: Tour) =>
        (!tour.permission || options.permissions.includes(tour.permission)) &&
        (!tour.simpleOnly || options.simple);

    if (!options.done.includes('welcome')) {
        return TOURS[0];
    }

    return (
        TOURS.find(
            (tour) =>
                tour.since !== undefined &&
                !options.done.includes(tour.key) &&
                allowed(tour) &&
                options.memberSince !== null &&
                options.memberSince < tour.since,
        ) ?? null
    );
}
