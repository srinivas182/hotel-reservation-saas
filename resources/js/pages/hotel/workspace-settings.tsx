import { Head, router, useForm } from '@inertiajs/react';
import { Eye, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Role = { id: number; name: string; owner: boolean };
type Checklist = {
    id: number;
    name: string;
    shift: 'opening' | 'closing';
    items: string[];
    active: boolean;
};

type Props = {
    roles: Role[];
    simpleRoles: number[];
    checklists: Checklist[];
};

function ChecklistForm({
    checklist,
    onDone,
}: {
    checklist?: Checklist;
    onDone?: () => void;
}) {
    const form = useForm({
        name: checklist?.name ?? '',
        shift: checklist?.shift ?? 'opening',
        lines: (checklist?.items ?? []).join('\n'),
        active: checklist?.active ?? true,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const itemError = Object.entries(errors).find(([key]) =>
        key.startsWith('items'),
    )?.[1];

    return (
        <form
            className="flex flex-col gap-2 rounded-lg border p-3 text-sm"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((data) => ({
                    name: data.name,
                    shift: data.shift,
                    active: data.active,
                    items: data.lines
                        .split('\n')
                        .map((line) => line.trim())
                        .filter(Boolean),
                }));
                const options = {
                    preserveScroll: true,
                    onSuccess: () => {
                        if (!checklist) {
                            form.reset();
                        }
                        onDone?.();
                    },
                };
                if (checklist) {
                    form.put(`/hotel/checklists/${checklist.id}`, options);
                } else {
                    form.post('/hotel/checklists', options);
                }
            }}
        >
            <div className="flex flex-wrap gap-2">
                <label className="grid flex-1 gap-1">
                    <span className="font-medium">{t('Name')}</span>
                    <Input
                        value={form.data.name}
                        maxLength={80}
                        placeholder={t('Morning opening')}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    <InputError message={errors.name} />
                </label>
                <label className="grid gap-1">
                    <span className="font-medium">{t('When')}</span>
                    <select
                        className="h-9 rounded-md border bg-transparent px-2"
                        value={form.data.shift}
                        onChange={(e) =>
                            form.setData(
                                'shift',
                                e.target.value as 'opening' | 'closing',
                            )
                        }
                    >
                        <option value="opening">
                            {t('Start of the shift')}
                        </option>
                        <option value="closing">{t('End of the shift')}</option>
                    </select>
                </label>
            </div>
            <label className="grid gap-1">
                <span className="font-medium">{t('Tasks, one per line')}</span>
                <textarea
                    className="min-h-28 rounded-md border bg-transparent px-3 py-2"
                    value={form.data.lines}
                    placeholder={t(
                        'Count the cash\nCheck tomorrow’s arrivals\nLock the safe',
                    )}
                    onChange={(e) => form.setData('lines', e.target.value)}
                />
                <InputError message={itemError} />
            </label>
            {checklist && (
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        className="size-4"
                        checked={form.data.active}
                        onChange={(e) =>
                            form.setData('active', e.target.checked)
                        }
                    />
                    {t('Show it on Today')}
                </label>
            )}
            <div className="flex gap-2">
                <Button type="submit" size="sm" disabled={form.processing}>
                    {checklist ? t('Save') : t('Add the checklist')}
                </Button>
                {checklist && (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => onDone?.()}
                    >
                        {t('Cancel')}
                    </Button>
                )}
            </div>
        </form>
    );
}

export default function WorkspaceSettings({
    roles,
    simpleRoles,
    checklists,
}: Props) {
    const form = useForm({ roles: simpleRoles });
    const [editing, setEditing] = useState<number | null>(null);

    return (
        <>
            <Head title={t('Simple mode and checklists')} />
            <div className="flex max-w-3xl flex-col gap-8 p-4">
                <Heading
                    title={t('Simple mode and checklists')}
                    description={t(
                        'Make the dashboard simpler for the roles that only need the day to day, and set the tasks of each shift.',
                    )}
                />

                <section className="flex flex-col gap-3">
                    <h2 className="font-medium">{t('Simple dashboard')}</h2>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'These roles open Today, Messages and Bookings with big buttons. Each person can still switch to the full view from their menu.',
                        )}
                    </p>
                    <form
                        className="flex flex-col gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.put('/hotel/workspace-settings/simple-roles', {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <ul className="flex flex-col divide-y rounded-lg border">
                            {roles.map((role) => (
                                <li
                                    key={role.id}
                                    className="flex items-center gap-3 px-3 py-2 text-sm"
                                >
                                    <label className="flex flex-1 items-center gap-2">
                                        <input
                                            type="checkbox"
                                            className="size-4"
                                            checked={form.data.roles.includes(
                                                role.id,
                                            )}
                                            onChange={(e) =>
                                                form.setData(
                                                    'roles',
                                                    e.target.checked
                                                        ? [
                                                              ...form.data
                                                                  .roles,
                                                              role.id,
                                                          ]
                                                        : form.data.roles.filter(
                                                              (id) =>
                                                                  id !==
                                                                  role.id,
                                                          ),
                                                )
                                            }
                                        />
                                        {role.name}
                                    </label>
                                    {!role.owner && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    '/hotel/workspace/view-as',
                                                    { role: role.id },
                                                )
                                            }
                                        >
                                            <Eye aria-hidden />
                                            {t('View as :role', {
                                                role: role.name,
                                            })}
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                        <Button
                            type="submit"
                            size="sm"
                            className="w-fit"
                            disabled={form.processing}
                        >
                            {t('Save')}
                        </Button>
                    </form>
                </section>

                <section className="flex flex-col gap-3">
                    <h2 className="font-medium">{t('Shift checklists')}</h2>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'They appear on Today; whoever is on shift ticks the tasks. You see what was done in the morning summary.',
                        )}
                    </p>
                    {checklists.map((checklist) =>
                        editing === checklist.id ? (
                            <ChecklistForm
                                key={checklist.id}
                                checklist={checklist}
                                onDone={() => setEditing(null)}
                            />
                        ) : (
                            <div
                                key={checklist.id}
                                className="flex items-start gap-3 rounded-lg border p-3 text-sm"
                            >
                                <div className="flex flex-1 flex-col">
                                    <span className="font-medium">
                                        {checklist.name}
                                        {!checklist.active && (
                                            <span className="ml-2 text-xs text-muted-foreground">
                                                {t('hidden')}
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {checklist.shift === 'opening'
                                            ? t('Start of the shift')
                                            : t('End of the shift')}{' '}
                                        ·{' '}
                                        {t(':count tasks', {
                                            count: checklist.items.length,
                                        })}
                                    </span>
                                </div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setEditing(checklist.id)}
                                >
                                    {t('Edit')}
                                </Button>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    aria-label={t('Delete :name', {
                                        name: checklist.name,
                                    })}
                                    onClick={() =>
                                        router.delete(
                                            `/hotel/checklists/${checklist.id}`,
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        ),
                    )}
                    <ChecklistForm />
                </section>
            </div>
        </>
    );
}
