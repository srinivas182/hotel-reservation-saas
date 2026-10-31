import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import UserController from '@/actions/App/Domains/Platform/Http/Controllers/UserController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/hooks/use-format';
import { t } from '@/lib/i18n';

type Row = {
    id: string;
    name: string;
    email: string;
    platform_role: string | null;
    hotels_count: number;
    deactivated: boolean;
    two_factor: boolean;
    last_login_at: string | null;
};

type Props = {
    users: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: { search: string };
    roles: string[];
    currentUserId: string;
    canManage: boolean;
};

const roleLabels: Record<string, string> = {
    super_admin: 'Super admin',
    support: 'Support',
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

export default function PlatformUsers({
    users,
    filters,
    roles,
    currentUserId,
    canManage,
}: Props) {
    const format = useFormat();
    const [search, setSearch] = useState(filters.search);

    return (
        <>
            <Head title={t('Users')} />
            <div className="flex max-w-5xl flex-col gap-6 p-4">
                <Heading
                    title={t('Users')}
                    description={t(':count accounts on the platform', {
                        count: users.total,
                    })}
                />

                {canManage && (
                    <section className="rounded-xl border p-4">
                        <Heading
                            variant="small"
                            title={t('Invite MCS staff')}
                            description={t(
                                'They receive an email to choose a password and must set up two-factor authentication.',
                            )}
                        />
                        <Form
                            {...UserController.store.form()}
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                            className="mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_1.4fr_auto_auto] sm:items-end"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="staff-first-name">
                                            {t('First name')}
                                        </Label>
                                        <Input
                                            id="staff-first-name"
                                            name="first_name"
                                            required
                                        />
                                        <InputError
                                            message={errors.first_name}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="staff-last-name">
                                            {t('Last name')}
                                        </Label>
                                        <Input
                                            id="staff-last-name"
                                            name="last_name"
                                            required
                                        />
                                        <InputError
                                            message={errors.last_name}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="staff-email">
                                            {t('Email')}
                                        </Label>
                                        <Input
                                            id="staff-email"
                                            name="email"
                                            type="email"
                                            required
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="staff-role">
                                            {t('Role')}
                                        </Label>
                                        <select
                                            id="staff-role"
                                            name="platform_role"
                                            className={selectClass}
                                            defaultValue="support"
                                        >
                                            {roles.map((role) => (
                                                <option key={role} value={role}>
                                                    {t(
                                                        roleLabels[role] ??
                                                            role,
                                                    )}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <Button disabled={processing}>
                                        {t('Invite')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>
                )}

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            UserController.index.url({ query: { search } }),
                            {},
                            { preserveState: true },
                        );
                    }}
                    className="max-w-sm"
                >
                    <Input
                        type="search"
                        placeholder={t('Search by name or email')}
                        aria-label={t('Search users')}
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </form>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">
                                    {t('Name')}
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    {t('Hotels')}
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    {t('Last sign-in')}
                                </th>
                                {canManage && (
                                    <th className="px-4 py-3 text-right font-medium">
                                        {t('Actions')}
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {users.data.map((row) => (
                                <tr key={row.id} className="border-t">
                                    <td className="px-4 py-3">
                                        <div className="font-medium">
                                            {row.name}
                                        </div>
                                        <div className="text-muted-foreground">
                                            {row.email}
                                        </div>
                                        <div className="mt-1 flex flex-wrap gap-1">
                                            {row.platform_role && (
                                                <Badge variant="secondary">
                                                    {t(
                                                        roleLabels[
                                                            row.platform_role
                                                        ] ?? row.platform_role,
                                                    )}
                                                </Badge>
                                            )}
                                            {row.deactivated && (
                                                <Badge variant="destructive">
                                                    {t('Deactivated')}
                                                </Badge>
                                            )}
                                            {!row.two_factor &&
                                                row.platform_role && (
                                                    <Badge variant="outline">
                                                        {t(
                                                            'Two-factor not set up',
                                                        )}
                                                    </Badge>
                                                )}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        {row.hotels_count}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {format.date(
                                            row.last_login_at,
                                            t('Never'),
                                        )}
                                    </td>
                                    {canManage && (
                                        <td className="px-4 py-3 text-right">
                                            {row.id !== currentUserId &&
                                                (row.deactivated ? (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            router.post(
                                                                UserController.reactivate.url(
                                                                    row.id,
                                                                ),
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {t('Reactivate')}
                                                    </Button>
                                                ) : (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-destructive"
                                                        onClick={() => {
                                                            if (
                                                                window.confirm(
                                                                    t(
                                                                        'Deactivate :name? They will be signed out everywhere.',
                                                                        {
                                                                            name: row.name,
                                                                        },
                                                                    ),
                                                                )
                                                            ) {
                                                                router.post(
                                                                    UserController.deactivate.url(
                                                                        row.id,
                                                                    ),
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                );
                                                            }
                                                        }}
                                                    >
                                                        {t('Deactivate')}
                                                    </Button>
                                                ))}
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {users.links.length > 3 && (
                    <nav
                        className="flex flex-wrap gap-1"
                        aria-label={t('Pagination')}
                    >
                        {users.links.map((link, index) => (
                            <Button
                                key={index}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={link.url === null}
                                onClick={() =>
                                    link.url &&
                                    router.get(
                                        link.url,
                                        {},
                                        { preserveState: true },
                                    )
                                }
                            >
                                <span
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            </Button>
                        ))}
                    </nav>
                )}
            </div>
        </>
    );
}

PlatformUsers.layout = {
    breadcrumbs: [{ title: 'Users', href: UserController.index() }],
};
