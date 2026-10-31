import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import RoleController from '@/actions/App/Domains/Access/Http/Controllers/RoleController';
import StaffController from '@/actions/App/Domains/Access/Http/Controllers/StaffController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type RoleRow = {
    id: number;
    name: string;
    is_system: boolean;
    permissions: string[];
    members_count: number;
};

type PermissionOption = { name: string; label: string; group: string };

type Props = { roles: RoleRow[]; permissions: PermissionOption[] };

function PermissionChecklist({
    permissions,
    selected,
    disabled,
}: {
    permissions: PermissionOption[];
    selected: string[];
    disabled?: boolean;
}) {
    const groups = [...new Set(permissions.map((p) => p.group))];

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {groups.map((group) => (
                <fieldset key={group} className="grid gap-2">
                    <legend className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        {group}
                    </legend>
                    {permissions
                        .filter((p) => p.group === group)
                        .map((permission) => (
                            <label
                                key={permission.name}
                                className="flex items-center gap-2 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    name="permissions[]"
                                    value={permission.name}
                                    defaultChecked={selected.includes(
                                        permission.name,
                                    )}
                                    disabled={disabled}
                                    className="size-4 rounded border-input"
                                />
                                {permission.label}
                            </label>
                        ))}
                </fieldset>
            ))}
        </div>
    );
}

export default function Roles({ roles, permissions }: Props) {
    const [editing, setEditing] = useState<number | null>(null);

    return (
        <>
            <Head title="Roles" />
            <div className="flex max-w-4xl flex-col gap-8 p-4">
                <Heading
                    title="Roles"
                    description="System roles are maintained by the platform. Create your own roles for other combinations of permissions."
                />

                <section className="rounded-xl border p-4">
                    <Heading variant="small" title="New custom role" />
                    <Form
                        {...RoleController.store.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="mt-4 flex flex-col gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid max-w-sm gap-2">
                                    <Label htmlFor="role-name">Role name</Label>
                                    <Input
                                        id="role-name"
                                        name="name"
                                        required
                                        maxLength={60}
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <PermissionChecklist
                                    permissions={permissions}
                                    selected={[]}
                                />
                                <div>
                                    <Button disabled={processing}>
                                        Create role
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>

                <ul className="flex flex-col gap-3">
                    {roles.map((role) => (
                        <li key={role.id} className="rounded-xl border p-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">{role.name}</span>
                                {role.is_system && (
                                    <Badge variant="secondary">System</Badge>
                                )}
                                <span className="text-sm text-muted-foreground">
                                    {role.members_count}{' '}
                                    {role.members_count === 1
                                        ? 'person'
                                        : 'people'}
                                </span>
                                {!role.is_system && (
                                    <div className="ml-auto flex gap-1">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setEditing(
                                                    editing === role.id
                                                        ? null
                                                        : role.id,
                                                )
                                            }
                                        >
                                            {editing === role.id
                                                ? 'Close'
                                                : 'Edit'}
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="text-destructive"
                                            onClick={() => {
                                                if (
                                                    window.confirm(
                                                        `Delete the role ${role.name}?`,
                                                    )
                                                ) {
                                                    router.delete(
                                                        RoleController.destroy.url(
                                                            role.id,
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }
                                            }}
                                        >
                                            Delete
                                        </Button>
                                    </div>
                                )}
                            </div>

                            {editing === role.id ? (
                                <Form
                                    {...RoleController.update.form(role.id)}
                                    options={{ preserveScroll: true }}
                                    onSuccess={() => setEditing(null)}
                                    className="mt-4 flex flex-col gap-4"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <div className="grid max-w-sm gap-2">
                                                <Label
                                                    htmlFor={`role-name-${role.id}`}
                                                >
                                                    Role name
                                                </Label>
                                                <Input
                                                    id={`role-name-${role.id}`}
                                                    name="name"
                                                    defaultValue={role.name}
                                                    required
                                                />
                                                <InputError
                                                    message={errors.name}
                                                />
                                            </div>
                                            <PermissionChecklist
                                                permissions={permissions}
                                                selected={role.permissions}
                                            />
                                            <div>
                                                <Button disabled={processing}>
                                                    Save role
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </Form>
                            ) : (
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {role.permissions.length === 0
                                        ? 'No permissions yet. Module permissions are added as modules are released.'
                                        : permissions
                                              .filter((p) =>
                                                  role.permissions.includes(
                                                      p.name,
                                                  ),
                                              )
                                              .map((p) => p.label)
                                              .join(' · ')}
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

Roles.layout = {
    breadcrumbs: [
        { title: 'Staff', href: StaffController.index() },
        { title: 'Roles', href: RoleController.index() },
    ],
};
