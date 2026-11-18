import { usePage } from '@inertiajs/react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { finishTour, useWorkspace } from '@/lib/workspace';
import { tourToShow } from './tours';
import type { TourStep } from './tours';

type Box = { top: number; left: number; width: number; height: number };

function visibleTarget(step: TourStep): HTMLElement | null {
    if (!step.target) {
        return null;
    }

    for (const element of document.querySelectorAll<HTMLElement>(
        `[data-tour="${step.target}"]`,
    )) {
        const rect = element.getBoundingClientRect();
        if (
            rect.width > 0 &&
            rect.height > 0 &&
            getComputedStyle(element).visibility !== 'hidden'
        ) {
            return element;
        }
    }

    return null;
}

/**
 * Shows the guided tour that is due (welcome first, then new features):
 * a card next to the real button, with Next, Back and Skip. Escape skips.
 */
export function TourRunner() {
    const workspace = useWorkspace();
    const page = usePage();
    const permissions = (
        page.props as unknown as { auth: { permissions: string[] } }
    ).auth.permissions;
    const [dismissed, setDismissed] = useState<string | null>(null);
    const [index, setIndex] = useState(0);
    const [box, setBox] = useState<Box | null>(null);
    const card = useRef<HTMLDivElement>(null);

    const tour = useMemo(
        () =>
            workspace && !workspace.viewAs
                ? tourToShow({
                      done: workspace.toursDone,
                      memberSince: workspace.memberSince,
                      simple: workspace.simple,
                      permissions,
                  })
                : null,
        [workspace, permissions],
    );

    // Steps for this person on this screen: their permissions, elements that are visible.
    const [steps, setSteps] = useState<TourStep[]>([]);
    useEffect(() => {
        if (!tour || dismissed === tour.key) {
            setSteps([]);

            return;
        }
        // Wait for the page to draw before looking for the buttons.
        const timer = window.setTimeout(() => {
            setSteps(
                tour
                    .steps()
                    .filter(
                        (step) =>
                            (!step.permission ||
                                permissions.includes(step.permission)) &&
                            (!step.target || visibleTarget(step) !== null),
                    ),
            );
            setIndex(0);
        }, 400);

        return () => window.clearTimeout(timer);
    }, [tour, dismissed, permissions, page.url]);

    const step = steps[index];

    useLayoutEffect(() => {
        if (!step) {
            setBox(null);

            return;
        }
        const element = visibleTarget(step);
        if (!element) {
            setBox(null);

            return;
        }
        element.scrollIntoView({ block: 'center', behavior: 'smooth' });
        const measure = () => {
            const rect = element.getBoundingClientRect();
            setBox({
                top: rect.top,
                left: rect.left,
                width: rect.width,
                height: rect.height,
            });
        };
        const timer = window.setTimeout(measure, 300);
        window.addEventListener('resize', measure);

        return () => {
            window.clearTimeout(timer);
            window.removeEventListener('resize', measure);
        };
    }, [step]);

    useEffect(() => {
        card.current?.focus();
    }, [step]);

    const end = useCallback(() => {
        if (tour) {
            setDismissed(tour.key);
            finishTour(tour.key);
        }
    }, [tour]);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && step) {
                end();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [step, end]);

    if (!tour || !step) {
        return null;
    }

    const last = index === steps.length - 1;
    const below =
        box !== null && box.top + box.height + 220 < window.innerHeight;
    const position: React.CSSProperties =
        box === null
            ? { left: '50%', top: '50%', transform: 'translate(-50%, -50%)' }
            : {
                  left: Math.max(
                      12,
                      Math.min(box.left, window.innerWidth - 332),
                  ),
                  top: below ? box.top + box.height + 12 : undefined,
                  bottom: below ? undefined : window.innerHeight - box.top + 12,
              };

    return (
        <>
            {box && (
                <div
                    aria-hidden
                    className="pointer-events-none fixed z-50 rounded-lg ring-4 ring-primary ring-offset-2 transition-all"
                    style={{
                        top: box.top - 4,
                        left: box.left - 4,
                        width: box.width + 8,
                        height: box.height + 8,
                    }}
                />
            )}
            <div
                ref={card}
                role="dialog"
                aria-labelledby="tour-title"
                aria-describedby="tour-text"
                tabIndex={-1}
                className="fixed z-50 w-[min(320px,calc(100vw-24px))] rounded-xl border bg-card p-4 shadow-xl outline-none"
                style={position}
            >
                <p className="text-xs text-muted-foreground">
                    {t(':n of :total', { n: index + 1, total: steps.length })}
                </p>
                <h2 id="tour-title" className="mt-1 font-semibold">
                    {step.title}
                </h2>
                <p id="tour-text" className="mt-1 text-sm">
                    {step.text}
                </p>
                <div className="mt-3 flex items-center gap-2">
                    <Button
                        size="sm"
                        onClick={() => (last ? end() : setIndex(index + 1))}
                    >
                        {last ? t('Done') : t('Next')}
                    </Button>
                    {index > 0 && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setIndex(index - 1)}
                        >
                            {t('Back')}
                        </Button>
                    )}
                    {!last && (
                        <Button
                            size="sm"
                            variant="ghost"
                            className="ml-auto"
                            onClick={end}
                        >
                            {t('Skip')}
                        </Button>
                    )}
                </div>
            </div>
        </>
    );
}
