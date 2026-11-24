import { Form, Head, router } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import RoomCategoryController from '@/actions/App/Domains/Rooms/Http/Controllers/RoomCategoryController';
import RoomClosureController from '@/actions/App/Domains/Rooms/Http/Controllers/RoomClosureController';
import RoomController from '@/actions/App/Domains/Rooms/Http/Controllers/RoomController';
import RoomsController from '@/actions/App/Domains/Rooms/Http/Controllers/RoomsController';
import {
    ChoiceCards,
    EmptyState,
    Field,
    FieldGroup,
    PageBody,
    PageHeader,
    Section,
    SidePanel,
    StatTile,
} from '@/components/page';
import type { Category, Option } from '@/components/rooms/category-form';
import { CategoryForm } from '@/components/rooms/category-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { askConfirm } from '@/lib/ask';

type Closure = {
    id: string;
    from_date: string;
    to_date: string;
    reason: string | null;
};

type Room = {
    id: string;
    room_category_id: string;
    number: string;
    floor: string | null;
    notes: string | null;
    status: string;
    closures: Closure[];
};

type Props = {
    locales: string[];
    defaultLocale: string;
    categories: Category[];
    rooms: Room[];
    statuses: Option[];
    amenities: Option[];
    canManage: boolean;
};

const selectClass = 'h-9 w-full disabled:opacity-50';

const statusTone: Record<string, string> = {
    in_service: 'bg-emerald-500',
    out_of_order: 'bg-red-500',
    out_of_service: 'bg-red-500',
    maintenance: 'bg-amber-500',
};

function RoomPanel({
    room,
    categories,
    statuses,
    canManage,
    onClose,
}: {
    room: Room | undefined;
    categories: Category[];
    statuses: Option[];
    canManage: boolean;
    onClose: () => void;
}) {
    const update = (changes: Partial<Room>) =>
        room &&
        router.put(
            RoomController.update.url(room.id),
            {
                room_category_id: room.room_category_id,
                number: room.number,
                floor: room.floor,
                status: room.status,
                notes: room.notes,
                ...changes,
            },
            { preserveScroll: true },
        );

    return (
        <SidePanel
            open={room !== undefined}
            onClose={onClose}
            title={room ? t('Room :number', { number: room.number }) : ''}
            description={
                room?.floor
                    ? t('Floor :floor', { floor: room.floor })
                    : undefined
            }
        >
            {room && (
                <div className="grid gap-6">
                    <FieldGroup title={t('Room')}>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label={t('Status')}>
                                <select
                                    className={selectClass}
                                    value={room.status}
                                    disabled={!canManage}
                                    onChange={(e) =>
                                        update({ status: e.target.value })
                                    }
                                >
                                    {statuses.map((s) => (
                                        <option key={s.value} value={s.value}>
                                            {s.label}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label={t('Category')}>
                                <select
                                    className={selectClass}
                                    value={room.room_category_id}
                                    disabled={!canManage}
                                    onChange={(e) =>
                                        update({
                                            room_category_id: e.target.value,
                                        })
                                    }
                                >
                                    {categories.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.display_name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        </div>
                    </FieldGroup>
                    <FieldGroup
                        title={t('Closed for dates')}
                        description={t(
                            'The room is not sold on these nights (works, private use).',
                        )}
                    >
                        {room.closures.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('Open every night.')}
                            </p>
                        ) : (
                            <ul className="divide-y rounded-lg border text-sm">
                                {room.closures.map((closure) => (
                                    <li
                                        key={closure.id}
                                        className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                                    >
                                        <span>
                                            <span className="tabular-nums">
                                                {closure.from_date} →{' '}
                                                {closure.to_date}
                                            </span>
                                            {closure.reason && (
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    · {closure.reason}
                                                </span>
                                            )}
                                        </span>
                                        {canManage && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    router.delete(
                                                        RoomClosureController.destroy.url(
                                                            closure.id,
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {t('Reopen')}
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {canManage && (
                            <Form
                                {...RoomClosureController.store.form(room.id)}
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="grid gap-3 sm:grid-cols-2"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <Field
                                            label={t('First night')}
                                            htmlFor={`from-${room.id}`}
                                            error={errors.from_date}
                                        >
                                            <Input
                                                id={`from-${room.id}`}
                                                type="date"
                                                name="from_date"
                                                required
                                            />
                                        </Field>
                                        <Field
                                            label={t('Last night')}
                                            htmlFor={`to-${room.id}`}
                                            error={errors.to_date}
                                        >
                                            <Input
                                                id={`to-${room.id}`}
                                                type="date"
                                                name="to_date"
                                                required
                                            />
                                        </Field>
                                        <Field
                                            label={t('Reason')}
                                            htmlFor={`reason-${room.id}`}
                                            className="sm:col-span-2"
                                        >
                                            <Input
                                                id={`reason-${room.id}`}
                                                name="reason"
                                                maxLength={200}
                                            />
                                        </Field>
                                        <div className="sm:col-span-2">
                                            <Button
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                {t('Close room')}
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        )}
                    </FieldGroup>
                    {canManage && (
                        <div className="flex justify-start border-t pt-4">
                            <Button
                                variant="ghost"
                                className="text-destructive"
                                onClick={async () => {
                                    if (
                                        await askConfirm(
                                            t('Remove room :number?', {
                                                number: room.number,
                                            }),
                                        )
                                    ) {
                                        router.delete(
                                            RoomController.destroy.url(room.id),
                                            {
                                                preserveScroll: true,
                                                onSuccess: onClose,
                                            },
                                        );
                                    }
                                }}
                            >
                                {t('Remove')}
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </SidePanel>
    );
}

function AddRooms({
    categories,
    onDone,
}: {
    categories: Category[];
    onDone: () => void;
}) {
    const [mode, setMode] = useState<'room' | 'bulk'>('bulk');

    return (
        <div className="grid gap-6">
            <ChoiceCards
                label={t('How many')}
                value={mode}
                onChange={(v) => setMode(v as 'room' | 'bulk')}
                options={[
                    {
                        value: 'bulk',
                        label: t('Add several rooms'),
                        hint: t('A range of numbers, for example 101 to 110.'),
                    },
                    { value: 'room', label: t('Add a room') },
                ]}
            />
            {mode === 'room' ? (
                <Form
                    {...RoomController.store.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess={['number']}
                    className="grid gap-4 sm:grid-cols-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field
                                label={t('Room number')}
                                htmlFor="new-number"
                                error={errors.number}
                            >
                                <Input
                                    id="new-number"
                                    name="number"
                                    required
                                    maxLength={20}
                                />
                            </Field>
                            <Field label={t('Floor')} htmlFor="new-floor">
                                <Input
                                    id="new-floor"
                                    name="floor"
                                    maxLength={20}
                                />
                            </Field>
                            <Field
                                label={t('Category')}
                                htmlFor="new-category"
                                className="sm:col-span-2"
                            >
                                <select
                                    id="new-category"
                                    name="room_category_id"
                                    className={selectClass}
                                >
                                    {categories.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.display_name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <input
                                type="hidden"
                                name="status"
                                value="in_service"
                            />
                            <div className="flex justify-end gap-2 border-t pt-4 sm:col-span-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={onDone}
                                >
                                    {t('Close')}
                                </Button>
                                <Button disabled={processing}>
                                    {t('Add room')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            ) : (
                <Form
                    {...RoomController.bulk.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={onDone}
                    className="grid gap-4 sm:grid-cols-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field
                                label={t('Category')}
                                htmlFor="bulk-category"
                                className="sm:col-span-2"
                            >
                                <select
                                    id="bulk-category"
                                    name="room_category_id"
                                    className={selectClass}
                                >
                                    {categories.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.display_name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label={t('From number')} htmlFor="bulk-from">
                                <Input
                                    id="bulk-from"
                                    name="from"
                                    type="number"
                                    min={0}
                                    required
                                />
                            </Field>
                            <Field
                                label={t('To number')}
                                htmlFor="bulk-to"
                                error={errors.to}
                            >
                                <Input
                                    id="bulk-to"
                                    name="to"
                                    type="number"
                                    min={0}
                                    required
                                />
                            </Field>
                            <Field
                                label={t('Prefix')}
                                htmlFor="bulk-prefix"
                                hint={t('Optional, for example A-')}
                            >
                                <Input
                                    id="bulk-prefix"
                                    name="prefix"
                                    maxLength={5}
                                    placeholder="A-"
                                />
                            </Field>
                            <Field label={t('Floor')} htmlFor="bulk-floor">
                                <Input
                                    id="bulk-floor"
                                    name="floor"
                                    maxLength={20}
                                />
                            </Field>
                            <div className="flex justify-end gap-2 border-t pt-4 sm:col-span-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={onDone}
                                >
                                    {t('Cancel')}
                                </Button>
                                <Button disabled={processing}>
                                    {t('Add rooms')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}

export default function Rooms({
    locales,
    defaultLocale,
    categories,
    rooms,
    statuses,
    amenities,
    canManage,
}: Props) {
    const [editing, setEditing] = useState<string | null>(null);
    const [adding, setAdding] = useState(false);
    const [roomId, setRoomId] = useState<string | null>(null);
    const total = rooms.length;
    const inService = rooms.filter((r) => r.status === 'in_service').length;
    const sellable = categories.reduce((n, c) => n + c.sellable_tonight, 0);
    const editingCategory = categories.find((c) => c.id === editing);
    const statusLabel = (v: string) =>
        statuses.find((s) => s.value === v)?.label ?? v;

    return (
        <>
            <Head title={t('Rooms')} />
            <PageBody>
                <PageHeader
                    title={t('Rooms')}
                    description={t(
                        ':total rooms, :inService in service. Rooms are sold by category.',
                        { total, inService },
                    )}
                    actions={
                        canManage && (
                            <>
                                {categories.length > 0 && (
                                    <Button
                                        variant="outline"
                                        onClick={() => setAdding(true)}
                                    >
                                        <Plus /> {t('Add rooms')}
                                    </Button>
                                )}
                                <Button onClick={() => setEditing('new')}>
                                    <Plus /> {t('Add category')}
                                </Button>
                            </>
                        )
                    }
                />

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <StatTile label={t('Rooms')} value={total} />
                    <StatTile label={t('In service')} value={inService} />
                    <StatTile label={t('Sellable tonight')} value={sellable} />
                    <StatTile
                        label={t('Categories')}
                        value={categories.length}
                    />
                </div>

                {categories.length === 0 && (
                    <EmptyState
                        text={t(
                            'Start by adding your room categories, for example Standard or Suite.',
                        )}
                    />
                )}

                {categories.map((category) => {
                    const list = rooms.filter(
                        (r) => r.room_category_id === category.id,
                    );

                    return (
                        <Section
                            key={category.id}
                            title={`${category.display_name} (${category.code})`}
                            description={t(
                                ':count rooms · :sellable sellable tonight · up to :max guests',
                                {
                                    count: category.rooms_count,
                                    sellable: category.sellable_tonight,
                                    max: category.max_occupancy,
                                },
                            )}
                            actions={
                                <>
                                    {!category.active && (
                                        <Badge variant="secondary">
                                            {t('Not on sale')}
                                        </Badge>
                                    )}
                                    {canManage && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                setEditing(category.id)
                                            }
                                        >
                                            <Pencil /> {t('Edit')}
                                        </Button>
                                    )}
                                    {canManage &&
                                        category.rooms_count === 0 && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-destructive"
                                                onClick={async () => {
                                                    if (
                                                        await askConfirm(
                                                            t('Delete :name?', {
                                                                name: category.display_name,
                                                            }),
                                                        )
                                                    ) {
                                                        router.delete(
                                                            RoomCategoryController.destroy.url(
                                                                category.id,
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        );
                                                    }
                                                }}
                                            >
                                                {t('Delete')}
                                            </Button>
                                        )}
                                </>
                            }
                        >
                            {list.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('No rooms in this category yet.')}
                                </p>
                            ) : (
                                <ul className="grid grid-cols-[repeat(auto-fill,minmax(7.5rem,1fr))] gap-2">
                                    {list.map((room) => (
                                        <li key={room.id}>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setRoomId(room.id)
                                                }
                                                className="flex w-full flex-col items-start gap-1 rounded-lg border bg-background px-3 py-2 text-left transition-colors hover:border-brand/50 hover:bg-muted/40"
                                            >
                                                <span className="flex w-full items-center justify-between gap-2">
                                                    <span className="font-semibold tabular-nums">
                                                        {room.number}
                                                    </span>
                                                    <span
                                                        className={cn(
                                                            'size-2 rounded-full',
                                                            statusTone[
                                                                room.status
                                                            ] ??
                                                                'bg-muted-foreground',
                                                        )}
                                                    />
                                                </span>
                                                <span className="truncate text-[11px] text-muted-foreground">
                                                    {statusLabel(room.status)}
                                                </span>
                                                {room.closures.length > 0 && (
                                                    <Badge
                                                        variant="outline"
                                                        className="text-[10px]"
                                                    >
                                                        {t('Closed')}
                                                    </Badge>
                                                )}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Section>
                    );
                })}
            </PageBody>

            <SidePanel
                wide
                open={editing !== null}
                onClose={() => setEditing(null)}
                title={
                    editingCategory
                        ? editingCategory.display_name
                        : t('Add category')
                }
            >
                {editing !== null && (
                    <CategoryForm
                        key={editing}
                        category={editingCategory}
                        locales={locales}
                        defaultLocale={defaultLocale}
                        amenities={amenities}
                        onDone={() => setEditing(null)}
                    />
                )}
            </SidePanel>
            <SidePanel
                open={adding}
                onClose={() => setAdding(false)}
                title={t('Add rooms')}
            >
                {adding && (
                    <AddRooms
                        categories={categories}
                        onDone={() => setAdding(false)}
                    />
                )}
            </SidePanel>
            <RoomPanel
                room={rooms.find((r) => r.id === roomId)}
                categories={categories}
                statuses={statuses}
                canManage={canManage}
                onClose={() => setRoomId(null)}
            />
        </>
    );
}

Rooms.layout = {
    breadcrumbs: [{ title: 'Rooms', href: RoomsController() }],
};
