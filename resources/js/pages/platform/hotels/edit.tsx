import { Form, Head, Link, router } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { useState } from 'react';
import HotelController from '@/actions/App/Domains/Platform/Http/Controllers/HotelController';
import HotelMemberController from '@/actions/App/Domains/Platform/Http/Controllers/HotelMemberController';
import HotelOverviewController from '@/actions/App/Domains/Platform/Http/Controllers/HotelOverviewController';
import {
    DataTable,
    Field,
    FieldGroup,
    PageBody,
    PageHeader,
    Section,
    SidePanel,
} from '@/components/page';
import type { HotelFormValues } from '@/components/platform/hotel-form';
import { HotelForm } from '@/components/platform/hotel-form';
import { StatusBadge } from '@/components/platform/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import PasswordInput from '@/components/password-input';
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

function AddMember({
    hotelId,
    memberRoles,
    onDone,
}: {
    hotelId: string;
    memberRoles: Props['memberRoles'];
    onDone: () => void;
}) {
    return (
        <Form
            {...HotelMemberController.store.form(hotelId)}
            options={{ preserveScroll: true }}
            resetOnSuccess
            onSuccess={onDone}
            className="grid gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <Field
                        label={t('E-mail')}
                        htmlFor="member-email"
                        error={errors.email}
                    >
                        <Input
                            id="member-email"
                            name="email"
                            type="email"
                            autoComplete="off"
                            required
                        />
                    </Field>
                    <Field label={t('Role')} htmlFor="member-role">
                        <select
                            id="member-role"
                            name="role"
                            defaultValue="reception"
                            className="h-9"
                        >
                            {memberRoles.map((role) => (
                                <option key={role.value} value={role.value}>
                                    {role.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <FieldGroup
                        title={t('New account')}
                        description={t(
                            'Only for an e-mail without an account: the account is created with this name and password, and they sign in with them.',
                        )}
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label={t('First name')}
                                htmlFor="member-first"
                                error={errors.first_name}
                            >
                                <Input
                                    id="member-first"
                                    name="first_name"
                                    autoComplete="off"
                                />
                            </Field>
                            <Field
                                label={t('Last name')}
                                htmlFor="member-last"
                                error={errors.last_name}
                            >
                                <Input
                                    id="member-last"
                                    name="last_name"
                                    autoComplete="off"
                                />
                            </Field>
                        </div>
                        <Field
                            label={t('Password')}
                            htmlFor="member-password"
                            error={errors.password}
                        >
                            <PasswordInput
                                id="member-password"
                                name="password"
                                autoComplete="new-password"
                            />
                        </Field>
                    </FieldGroup>
                    <div className="flex justify-end gap-2 border-t pt-4">
                        <Button type="button" variant="ghost" onClick={onDone}>
                            {t('Cancel')}
                        </Button>
                        <Button disabled={processing}>
                            {t('Add to hotel')}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

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
    const [adding, setAdding] = useState(false);

    return (
        <>
            <Head title={hotel.name} />
            <PageBody>
                <PageHeader
                    title={hotel.name}
                    description={t('Hotel details')}
                    actions={
                        <>
                            <StatusBadge status={hotel.status} />
                            <Button variant="outline" asChild>
                                <Link
                                    href={HotelOverviewController.show(
                                        hotel.id,
                                    )}
                                >
                                    {t('Hotel overview')}
                                </Link>
                            </Button>
                        </>
                    }
                />
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

                <Section
                    flush
                    title={t('Staff')}
                    description={t('People who can work in this hotel.')}
                    actions={
                        canManageMembers && (
                            <Button size="sm" onClick={() => setAdding(true)}>
                                <UserPlus /> {t('Add to hotel')}
                            </Button>
                        )
                    }
                >
                    <DataTable
                        rows={members}
                        rowKey={(m) => m.id}
                        empty={t('No staff yet.')}
                        columns={[
                            {
                                key: 'name',
                                header: t('Name'),
                                cell: (m) => (
                                    <div>
                                        <div className="font-medium">
                                            {m.name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {m.email}
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'status',
                                header: t('Status'),
                                cell: (m) => (
                                    <div className="flex flex-wrap gap-1">
                                        {m.is_owner && (
                                            <Badge>{t('Owner')}</Badge>
                                        )}
                                        <Badge variant="outline">
                                            {m.status}
                                        </Badge>
                                    </div>
                                ),
                            },
                            {
                                key: 'actions',
                                header: '',
                                align: 'right',
                                cell: (m) =>
                                    canManageMembers &&
                                    !m.is_owner && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="text-destructive"
                                            onClick={() => {
                                                if (
                                                    window.confirm(
                                                        t(
                                                            'Remove :name from this hotel?',
                                                            { name: m.name },
                                                        ),
                                                    )
                                                ) {
                                                    router.delete(
                                                        HotelMemberController.destroy.url(
                                                            {
                                                                hotel: hotel.id,
                                                                user: m.id,
                                                            },
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }
                                            }}
                                        >
                                            {t('Remove')}
                                        </Button>
                                    ),
                            },
                        ]}
                    />
                </Section>
            </PageBody>
            {canManageMembers && (
                <SidePanel
                    open={adding}
                    onClose={() => setAdding(false)}
                    title={t('Add to hotel')}
                    description={t(
                        'Add someone by e-mail. For a new e-mail, also enter the name and a password: the account is created and they sign in with them.',
                    )}
                >
                    <AddMember
                        hotelId={hotel.id}
                        memberRoles={memberRoles}
                        onDone={() => setAdding(false)}
                    />
                </SidePanel>
            )}
        </>
    );
}

HotelsEdit.layout = {
    breadcrumbs: [{ title: 'Hotels', href: HotelController.index() }],
};
