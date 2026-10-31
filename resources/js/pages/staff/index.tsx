import { Form, Head, Link, router } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import InvitationController from '@/actions/App/Domains/Access/Http/Controllers/InvitationController';
import RoleController from '@/actions/App/Domains/Access/Http/Controllers/RoleController';
import StaffController from '@/actions/App/Domains/Access/Http/Controllers/StaffController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Member = {
    id: string;
    name: string;
    email: string;
    status: string;
    role_id: number | null;
    role_name: string | null;
    is_owner: boolean;
    is_primary_owner: boolean;
    last_login_at: string | null;
};

type Invitation = {
    id: string;
    email: string;
    role_name: string | null;
    status: string;
    expires_at: string;
};

type RoleOption = { id: number; name: string; is_owner: boolean };

type Props = {
    members: Member[];
    invitations: Invitation[];
    roles: RoleOption[];
    currentUserId: string;
    isOwner: boolean;
    can: { invite: boolean; manage: boolean; manageRoles: boolean };
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-2 py-1 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50';

function formatDate(value: string | null): string {
    return value
        ? new Date(value).toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : 'Never';
}

export default function StaffIndex({
    members,
    invitations,
    roles,
    currentUserId,
    isOwner,
    can,
}: Props) {
    const assignableRoles = roles.filter((role) => isOwner || !role.is_owner);

    return (
        <>
            <Head title="Staff" />
            <div className="flex max-w-5xl flex-col gap-8 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Staff"
                        description="People who can work in this hotel and what they can do."
                    />
                    {can.manageRoles && (
                        <Button variant="outline" asChild>
                            <Link href={RoleController.index()}>
                                <ShieldCheck /> Roles
                            </Link>
                        </Button>
                    )}
                </div>

                {can.invite && (
                    <section className="rounded-xl border p-4">
                        <Heading
                            variant="small"
                            title="Invite someone"
                            description="They receive an email with a link that is valid for 7 days."
                        />
                        <Form
                            {...InvitationController.store.form()}
                            options={{ preserveScroll: true }}
                            resetOnSuccess={['email']}
                            className="mt-4 grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="invite-email">
                                            Email
                                        </Label>
                                        <Input
                                            id="invite-email"
                                            name="email"
                                            type="email"
                                            required
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="invite-role">
                                            Role
                                        </Label>
                                        <select
                                            id="invite-role"
                                            name="role_id"
                                            className={selectClass}
                                            defaultValue={
                                                assignableRoles.find(
                                                    (role) => !role.is_owner,
                                                )?.id
                                            }
                                        >
                                            {assignableRoles.map((role) => (
                                                <option
                                                    key={role.id}
                                                    value={role.id}
                                                >
                                                    {role.name}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError message={errors.role_id} />
                                    </div>
                                    <Button disabled={processing}>
                                        Send invitation
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>
                )}

                <section className="flex flex-col gap-3">
                    <Heading
                        variant="small"
                        title={`Team (${members.length})`}
                    />
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-3 font-medium">
                                        Name
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Role
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Last sign-in
                                    </th>
                                    {can.manage && (
                                        <th className="px-4 py-3 text-right font-medium">
                                            Actions
                                        </th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {members.map((member) => {
                                    const isSelf = member.id === currentUserId;
                                    const ownerLocked =
                                        member.is_owner && !isOwner;
                                    const editable =
                                        can.manage && !isSelf && !ownerLocked;

                                    return (
                                        <tr
                                            key={member.id}
                                            className="border-t align-middle"
                                        >
                                            <td className="px-4 py-3">
                                                <div className="font-medium">
                                                    {member.name}
                                                    {isSelf && (
                                                        <span className="ml-1 text-muted-foreground">
                                                            (you)
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="text-muted-foreground">
                                                    {member.email}
                                                </div>
                                                <div className="mt-1 flex gap-1">
                                                    {member.is_primary_owner && (
                                                        <Badge variant="secondary">
                                                            Primary owner
                                                        </Badge>
                                                    )}
                                                    {member.status !==
                                                        'active' && (
                                                        <Badge variant="destructive">
                                                            Suspended
                                                        </Badge>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                {editable ? (
                                                    <select
                                                        aria-label={`Role for ${member.name}`}
                                                        className={selectClass}
                                                        value={
                                                            member.role_id ?? ''
                                                        }
                                                        onChange={(event) =>
                                                            router.put(
                                                                StaffController.updateRole.url(
                                                                    member.id,
                                                                ),
                                                                {
                                                                    role_id:
                                                                        Number(
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        ),
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {assignableRoles.map(
                                                            (role) => (
                                                                <option
                                                                    key={
                                                                        role.id
                                                                    }
                                                                    value={
                                                                        role.id
                                                                    }
                                                                >
                                                                    {role.name}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                ) : (
                                                    (member.role_name ?? '—')
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {formatDate(
                                                    member.last_login_at,
                                                )}
                                            </td>
                                            {can.manage && (
                                                <td className="px-4 py-3">
                                                    {editable && (
                                                        <div className="flex justify-end gap-1">
                                                            {isOwner &&
                                                                member.is_owner &&
                                                                !member.is_primary_owner && (
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        onClick={() =>
                                                                            router.post(
                                                                                StaffController.makePrimaryOwner.url(
                                                                                    member.id,
                                                                                ),
                                                                                {},
                                                                                {
                                                                                    preserveScroll: true,
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        Make
                                                                        primary
                                                                        owner
                                                                    </Button>
                                                                )}
                                                            {member.status ===
                                                            'active' ? (
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        router.post(
                                                                            StaffController.suspend.url(
                                                                                member.id,
                                                                            ),
                                                                            {},
                                                                            {
                                                                                preserveScroll: true,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    Suspend
                                                                </Button>
                                                            ) : (
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        router.post(
                                                                            StaffController.reactivate.url(
                                                                                member.id,
                                                                            ),
                                                                            {},
                                                                            {
                                                                                preserveScroll: true,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    Restore
                                                                </Button>
                                                            )}
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                className="text-destructive"
                                                                onClick={() => {
                                                                    if (
                                                                        window.confirm(
                                                                            `Remove ${member.name} from this hotel?`,
                                                                        )
                                                                    ) {
                                                                        router.delete(
                                                                            StaffController.destroy.url(
                                                                                member.id,
                                                                            ),
                                                                            {
                                                                                preserveScroll: true,
                                                                            },
                                                                        );
                                                                    }
                                                                }}
                                                            >
                                                                Remove
                                                            </Button>
                                                        </div>
                                                    )}
                                                </td>
                                            )}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </section>

                {invitations.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <Heading variant="small" title="Invitations" />
                        <ul className="divide-y rounded-xl border">
                            {invitations.map((invitation) => (
                                <li
                                    key={invitation.id}
                                    className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate font-medium">
                                            {invitation.email}
                                        </div>
                                        <div className="text-muted-foreground">
                                            {invitation.role_name} ·{' '}
                                            {invitation.status === 'expired'
                                                ? 'Expired'
                                                : `Expires ${formatDate(invitation.expires_at)}`}
                                        </div>
                                    </div>
                                    {can.invite && (
                                        <div className="flex gap-1">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        InvitationController.resend.url(
                                                            invitation.id,
                                                        ),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Resend
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-destructive"
                                                onClick={() =>
                                                    router.delete(
                                                        InvitationController.destroy.url(
                                                            invitation.id,
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Cancel
                                            </Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

StaffIndex.layout = {
    breadcrumbs: [{ title: 'Staff', href: StaffController.index() }],
};
