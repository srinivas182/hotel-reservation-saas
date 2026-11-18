import { router } from '@inertiajs/react';
import { t } from '@/lib/i18n';
import { ScreenTip } from './screen-tip';

export type DayChecklist = {
    id: number;
    name: string;
    shift: string;
    items: {
        text: string;
        key: string;
        done: boolean;
        by: string | null;
        at: string | null;
    }[];
    done: number;
    total: number;
};

/** Addition 3: the shift checklists of the day, ticked by whoever is on shift. */
export function ShiftChecklistsCard({
    lists,
    big,
}: {
    lists: DayChecklist[];
    big: boolean;
}) {
    if (lists.length === 0) {
        return null;
    }

    return (
        <section
            id="checklists"
            data-tour="checklists"
            className="flex flex-col gap-3 rounded-xl border p-4"
        >
            <h2 className="flex items-center gap-2 font-medium">
                {t('Shift checklists')}
                <ScreenTip
                    title={t('Shift checklists')}
                    text={t(
                        'Tasks the owner set for the start and the end of the shift. Tick each one when it is done; the owner sees them in the morning summary.',
                    )}
                />
            </h2>
            {lists.map((list) => (
                <fieldset key={list.id} className="flex flex-col gap-1">
                    <legend className="mb-1 text-sm font-medium">
                        {list.name}{' '}
                        <span className="text-muted-foreground">
                            {t(':done/:total', {
                                done: list.done,
                                total: list.total,
                            })}
                        </span>
                    </legend>
                    {list.items.map((item) => (
                        <label
                            key={item.key}
                            className={`flex items-center gap-3 rounded-lg px-2 ${big ? 'min-h-12 text-base' : 'min-h-9 text-sm'} hover:bg-muted/50`}
                        >
                            <input
                                type="checkbox"
                                className={big ? 'size-6' : 'size-4'}
                                checked={item.done}
                                onChange={(e) =>
                                    router.post(
                                        `/hotel/checklists/${list.id}/tick`,
                                        {
                                            key: item.key,
                                            done: e.target.checked,
                                        },
                                        {
                                            preserveScroll: true,
                                            preserveState: true,
                                            only: ['checklists', 'nextUp'],
                                        },
                                    )
                                }
                            />
                            <span
                                className={
                                    item.done
                                        ? 'flex-1 text-muted-foreground line-through'
                                        : 'flex-1'
                                }
                            >
                                {item.text}
                            </span>
                            {item.done && item.by && (
                                <span className="text-xs text-muted-foreground">
                                    {item.by} · {item.at}
                                </span>
                            )}
                        </label>
                    ))}
                </fieldset>
            ))}
        </section>
    );
}
