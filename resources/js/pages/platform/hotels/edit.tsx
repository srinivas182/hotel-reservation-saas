import { Form, Head, router } from '@inertiajs/react';
import HotelController from '@/actions/App/Domains/Platform/Http/Controllers/HotelController';
import HotelMemberController from '@/actions/App/Domains/Platform/Http/Controllers/HotelMemberController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import type { HotelFormValues } from '@/components/platform/hotel-form';
import { HotelForm } from '@/components/platform/hotel-form';
import { StatusBadge } from '@/components/platform/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

type Member = {
    id: string;
    name: string;
    email: string;
    status: string;
    is_owner: boolean;
};

type Props = {
    hotel: HotelFormValues & { id: string; status: string };
    members: Member[];
    statuses: string[];
    locales: string[];
    timezones: string[];
    canUpdate: boolean;
    canManageMembers: boolean;
    memberRoles: { value: string; label: string }[];
};

export default function HotelsEdit({
    hotel,
    members,
    statuses,
    locales,
    timezones,
    canUpdate,
    canManageMembers,
    memberRoles,
}: Props) {
    return (
        <>
            <Head title={hotel.name} />
            <div className="flex max-w-3xl flex-col gap-10 p-4">
                <section className="flex flex-col gap-6">
                    <div className="flex items-start justify-between gap-4">
                        <Heading
                            title={hotel.name}
                            description={t('Hotel details')}
                        />
                        <StatusBadge status={hotel.status} />
                    </div>
                    <HotelForm
                        action={HotelController.update.form(hotel.id)}
                        values={hotel}
                        statuses={statuses}
                        locales={locales}
                        timezones={timezones}
                        showStatus
                        readOnly={!canUpdate}
                        submitLabel={t('Save changes')}
                    />
                </section>

                <section className="flex flex-col gap-4">
                    <Heading
                        variant="small"
                        title={t('Staff')}
                        description={t('People who can work in this hotel.')}
                    />

                    <ul className="divide-y rounded-xl border">
                        {members.length === 0 && (
                            <li className="px-4 py-6 text-center text-sm text-muted-foreground">
                                {t('No staff yet.')}
                            </li>
                        )}
                        {members.map((member) => (
                            <li
                                key={member.id}
                                className="flex items-center gap-3 px-4 py-3 text-sm"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="truncate font-medium">
                                        {member.name}
                                    </div>
                                    <div className="truncate text-muted-foreground">
                                        {member.email}
                                    </div>
                                </div>
                                {member.is_owner && (
                                    <Badge variant="secondary">
                                        {t('Owner')}
                                    </Badge>
                                )}
                                {member.status !== 'active' && (
                                    <Badge variant="outline">
                                        {member.status}
                                    </Badge>
                                )}
                                {canManageMembers && !member.is_owner && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => {
                                            if (
                                                window.confirm(
                                                    t(
                                                        'Remove :name from this hotel?',
                                                        { name: member.name },
                                                    ),
                                                )
                                            ) {
                                                router.delete(
                                                    HotelMemberController.destroy.url(
                                                        {
                                                            hotel: hotel.id,
                                                            user: member.id,
                                                        },
                                                    ),
                                                    { preserveScroll: true },
                                                );
                                            }
                                        }}
                                    >
                                        {t('Remove')}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>

                    {canManageMembers && (
                        <Form
                            {...HotelMemberController.store.form(hotel.id)}
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                            className="flex flex-col gap-2 sm:flex-row sm:items-start"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="flex-1">
                                        <Input
                                            name="email"
                                            type="email"
                                            placeholder={t(
                                                'Email of an existing user',
                                            )}
                                            aria-label={t(
                                                'Email of the user to add',
                                            )}
                                            required
                                        />
                                        <InputError
                                            className="mt-2"
                                            message={errors.email}
                                        />
                                    </div>
                                    <select
                                        name="role"
                                        aria-label={t('Role')}
                                        defaultValue="reception"
                                        className="flex h-9 rounded-md border border-input bg-transparent px-2 py-1 text-sm shadow-xs"
                                    >
                                        {memberRoles.map((role) => (
                                            <option
                                                key={role.value}
                                                value={role.value}
                                            >
                                                {role.label}
                                            </option>
                                        ))}
                                    </select>
                                    <Button disabled={processing}>
                                        {t('Add to hotel')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </section>
            </div>
        </>
    );
}

HotelsEdit.layout = {
    breadcrumbs: [{ title: 'Hotels', href: HotelController.index() }],
};
