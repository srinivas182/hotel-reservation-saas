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
};

export default function HotelsEdit({
    hotel,
    members,
    statuses,
    locales,
    timezones,
    canUpdate,
    canManageMembers,
}: Props) {
    return (
        <>
            <Head title={hotel.name} />
            <div className="flex max-w-3xl flex-col gap-10 p-4">
                <section className="flex flex-col gap-6">
                    <div className="flex items-start justify-between gap-4">
                        <Heading
                            title={hotel.name}
                            description="Hotel details"
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
                        submitLabel="Save changes"
                    />
                </section>

                <section className="flex flex-col gap-4">
                    <Heading
                        variant="small"
                        title="Staff"
                        description="People who can work in this hotel. Invitations and roles arrive in the next sprint."
                    />

                    <ul className="divide-y rounded-xl border">
                        {members.length === 0 && (
                            <li className="px-4 py-6 text-center text-sm text-muted-foreground">
                                No staff yet.
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
                                    <Badge variant="secondary">Owner</Badge>
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
                                                    `Remove ${member.name} from this hotel?`,
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
                                        Remove
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
                                            placeholder="Email of an existing user"
                                            aria-label="Email of the user to add"
                                            required
                                        />
                                        <InputError
                                            className="mt-2"
                                            message={errors.email}
                                        />
                                    </div>
                                    <Button disabled={processing}>
                                        Add to hotel
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
