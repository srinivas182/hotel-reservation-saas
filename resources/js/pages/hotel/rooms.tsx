import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import RoomCategoryController from '@/actions/App/Domains/Rooms/Http/Controllers/RoomCategoryController';
import RoomClosureController from '@/actions/App/Domains/Rooms/Http/Controllers/RoomClosureController';
import RoomController from '@/actions/App/Domains/Rooms/Http/Controllers/RoomController';
import RoomsController from '@/actions/App/Domains/Rooms/Http/Controllers/RoomsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import type { Category, Option } from '@/components/rooms/category-form';
import { CategoryForm } from '@/components/rooms/category-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

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

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-2 py-1 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50';

function RoomRow({
    room,
    categories,
    statuses,
    canManage,
}: {
    room: Room;
    categories: Category[];
    statuses: Option[];
    canManage: boolean;
}) {
    const [closing, setClosing] = useState(false);

    const update = (changes: Partial<Room>) =>
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
        <li className="flex flex-col gap-2 px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-3">
                <span className="w-16 font-medium">{room.number}</span>
                <span className="w-20 text-muted-foreground">
                    {room.floor ? t('Floor :floor', { floor: room.floor }) : ''}
                </span>
                <div className="w-40">
                    <select
                        aria-label={t('Status of room :number', {
                            number: room.number,
                        })}
                        className={selectClass}
                        value={room.status}
                        disabled={!canManage}
                        onChange={(e) => update({ status: e.target.value })}
                    >
                        {statuses.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="w-48">
                    <select
                        aria-label={t('Category of room :number', {
                            number: room.number,
                        })}
                        className={selectClass}
                        value={room.room_category_id}
                        disabled={!canManage}
                        onChange={(e) =>
                            update({ room_category_id: e.target.value })
                        }
                    >
                        {categories.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.display_name}
                            </option>
                        ))}
                    </select>
                </div>
                {canManage && (
                    <div className="ml-auto flex gap-1">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setClosing(!closing)}
                        >
                            {t('Close for dates')}
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-destructive"
                            onClick={() => {
                                if (
                                    window.confirm(
                                        t('Remove room :number?', {
                                            number: room.number,
                                        }),
                                    )
                                ) {
                                    router.delete(
                                        RoomController.destroy.url(room.id),
                                        { preserveScroll: true },
                                    );
                                }
                            }}
                        >
                            {t('Remove')}
                        </Button>
                    </div>
                )}
            </div>

            {room.closures.map((closure) => (
                <div
                    key={closure.id}
                    className="flex flex-wrap items-center gap-2 pl-16 text-muted-foreground"
                >
                    <Badge variant="outline">{t('Closed')}</Badge>
                    {closure.from_date} → {closure.to_date}
                    {closure.reason ? ` · ${closure.reason}` : ''}
                    {canManage && (
                        <Button
                            variant="link"
                            size="sm"
                            className="h-auto p-0"
                            onClick={() =>
                                router.delete(
                                    RoomClosureController.destroy.url(
                                        closure.id,
                                    ),
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {t('Reopen')}
                        </Button>
                    )}
                </div>
            ))}

            {closing && (
                <Form
                    {...RoomClosureController.store.form(room.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setClosing(false)}
                    className="grid gap-2 pl-16 sm:grid-cols-[auto_auto_1fr_auto] sm:items-end"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-1">
                                <Label htmlFor={`from-${room.id}`}>
                                    {t('First night')}
                                </Label>
                                <Input
                                    id={`from-${room.id}`}
                                    type="date"
                                    name="from_date"
                                    required
                                />
                                <InputError message={errors.from_date} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor={`to-${room.id}`}>
                                    {t('Last night')}
                                </Label>
                                <Input
                                    id={`to-${room.id}`}
                                    type="date"
                                    name="to_date"
                                    required
                                />
                                <InputError message={errors.to_date} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor={`reason-${room.id}`}>
                                    {t('Reason')}
                                </Label>
                                <Input
                                    id={`reason-${room.id}`}
                                    name="reason"
                                    maxLength={200}
                                />
                            </div>
                            <Button disabled={processing}>
                                {t('Close room')}
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </li>
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
    const [adding, setAdding] = useState<'room' | 'bulk' | null>(null);
    const total = rooms.length;
    const inService = rooms.filter((r) => r.status === 'in_service').length;

    return (
        <>
            <Head title={t('Rooms')} />
            <div className="flex max-w-5xl flex-col gap-8 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={t('Rooms')}
                        description={t(
                            ':total rooms, :inService in service. Rooms are sold by category.',
                            { total, inService },
                        )}
                    />
                    {canManage && editing === null && (
                        <Button onClick={() => setEditing('new')}>
                            {t('Add category')}
                        </Button>
                    )}
                </div>

                {editing === 'new' && (
                    <CategoryForm
                        locales={locales}
                        defaultLocale={defaultLocale}
                        amenities={amenities}
                        onDone={() => setEditing(null)}
                    />
                )}

                {categories.length === 0 && editing === null && (
                    <p className="rounded-xl border px-4 py-8 text-center text-sm text-muted-foreground">
                        {t(
                            'Start by adding your room categories, for example Standard or Suite.',
                        )}
                    </p>
                )}

                {categories.map((category) =>
                    editing === category.id ? (
                        <CategoryForm
                            key={category.id}
                            category={category}
                            locales={locales}
                            defaultLocale={defaultLocale}
                            amenities={amenities}
                            onDone={() => setEditing(null)}
                        />
                    ) : (
                        <section
                            key={category.id}
                            className="flex flex-col gap-2"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="text-base font-semibold">
                                    {category.display_name}{' '}
                                    <span className="text-muted-foreground">
                                        ({category.code})
                                    </span>
                                </h2>
                                {!category.active && (
                                    <Badge variant="secondary">
                                        {t('Not on sale')}
                                    </Badge>
                                )}
                                <span className="text-sm text-muted-foreground">
                                    {t(
                                        ':count rooms · :sellable sellable tonight · up to :max guests',
                                        {
                                            count: category.rooms_count,
                                            sellable: category.sellable_tonight,
                                            max: category.max_occupancy,
                                        },
                                    )}
                                </span>
                                {canManage && (
                                    <div className="ml-auto flex gap-1">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setEditing(category.id)
                                            }
                                        >
                                            {t('Edit')}
                                        </Button>
                                        {category.rooms_count === 0 && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-destructive"
                                                onClick={() => {
                                                    if (
                                                        window.confirm(
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
                                    </div>
                                )}
                            </div>
                            <ul className="divide-y rounded-xl border">
                                {rooms.filter(
                                    (r) => r.room_category_id === category.id,
                                ).length === 0 && (
                                    <li className="px-4 py-4 text-sm text-muted-foreground">
                                        {t('No rooms in this category yet.')}
                                    </li>
                                )}
                                {rooms
                                    .filter(
                                        (r) =>
                                            r.room_category_id === category.id,
                                    )
                                    .map((room) => (
                                        <RoomRow
                                            key={room.id}
                                            room={room}
                                            categories={categories}
                                            statuses={statuses}
                                            canManage={canManage}
                                        />
                                    ))}
                            </ul>
                        </section>
                    ),
                )}

                {canManage && categories.length > 0 && (
                    <section className="flex flex-col gap-3 rounded-xl border p-4">
                        <div className="flex gap-2">
                            <Button
                                variant={
                                    adding === 'room' ? 'default' : 'outline'
                                }
                                onClick={() =>
                                    setAdding(adding === 'room' ? null : 'room')
                                }
                            >
                                {t('Add a room')}
                            </Button>
                            <Button
                                variant={
                                    adding === 'bulk' ? 'default' : 'outline'
                                }
                                onClick={() =>
                                    setAdding(adding === 'bulk' ? null : 'bulk')
                                }
                            >
                                {t('Add several rooms')}
                            </Button>
                        </div>

                        {adding === 'room' && (
                            <Form
                                {...RoomController.store.form()}
                                options={{ preserveScroll: true }}
                                resetOnSuccess={['number']}
                                className="grid gap-3 sm:grid-cols-4 sm:items-end"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-1">
                                            <Label htmlFor="new-number">
                                                {t('Room number')}
                                            </Label>
                                            <Input
                                                id="new-number"
                                                name="number"
                                                required
                                                maxLength={20}
                                            />
                                            <InputError
                                                message={errors.number}
                                            />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="new-category">
                                                {t('Category')}
                                            </Label>
                                            <select
                                                id="new-category"
                                                name="room_category_id"
                                                className={selectClass}
                                            >
                                                {categories.map((c) => (
                                                    <option
                                                        key={c.id}
                                                        value={c.id}
                                                    >
                                                        {c.display_name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="new-floor">
                                                {t('Floor')}
                                            </Label>
                                            <Input
                                                id="new-floor"
                                                name="floor"
                                                maxLength={20}
                                            />
                                        </div>
                                        <input
                                            type="hidden"
                                            name="status"
                                            value="in_service"
                                        />
                                        <Button disabled={processing}>
                                            {t('Add room')}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}

                        {adding === 'bulk' && (
                            <Form
                                {...RoomController.bulk.form()}
                                options={{ preserveScroll: true }}
                                className="grid gap-3 sm:grid-cols-6 sm:items-end"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-1 sm:col-span-2">
                                            <Label htmlFor="bulk-category">
                                                {t('Category')}
                                            </Label>
                                            <select
                                                id="bulk-category"
                                                name="room_category_id"
                                                className={selectClass}
                                            >
                                                {categories.map((c) => (
                                                    <option
                                                        key={c.id}
                                                        value={c.id}
                                                    >
                                                        {c.display_name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="bulk-prefix">
                                                {t('Prefix')}
                                            </Label>
                                            <Input
                                                id="bulk-prefix"
                                                name="prefix"
                                                maxLength={5}
                                                placeholder="A-"
                                            />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="bulk-from">
                                                {t('From number')}
                                            </Label>
                                            <Input
                                                id="bulk-from"
                                                name="from"
                                                type="number"
                                                min={0}
                                                required
                                            />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="bulk-to">
                                                {t('To number')}
                                            </Label>
                                            <Input
                                                id="bulk-to"
                                                name="to"
                                                type="number"
                                                min={0}
                                                required
                                            />
                                            <InputError message={errors.to} />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="bulk-floor">
                                                {t('Floor')}
                                            </Label>
                                            <Input
                                                id="bulk-floor"
                                                name="floor"
                                                maxLength={20}
                                            />
                                        </div>
                                        <Button
                                            className="sm:col-span-6 sm:w-fit"
                                            disabled={processing}
                                        >
                                            {t('Add rooms')}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                    </section>
                )}
            </div>
        </>
    );
}

Rooms.layout = {
    breadcrumbs: [{ title: 'Rooms', href: RoomsController() }],
};
