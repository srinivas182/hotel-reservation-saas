import { Form, Head, router } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { useState } from 'react';
import UserController from '@/actions/App/Domains/Platform/Http/Controllers/UserController';
import {
    DataTable,
    Field,
    PageBody,
    PageHeader,
    Pagination,
    SearchBox,
    Section,
    SidePanel,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    operations: 'Operations',
    finance: 'Finance',
    sales: 'Sales',
};

const initials = (name: string) =>
    name
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p.charAt(0).toUpperCase())
        .join('');

function InvitePanel({
    open,
    onClose,
    roles,
}: {
    open: boolean;
    onClose: () => void;
    roles: string[];
}) {
    return (
        <SidePanel
            open={open}
            onClose={onClose}
            title={t('Invite MCS staff')}
            description={t(
                'They receive an email to choose a password and must set up two-factor authentication.',
            )}
        >
            <Form
                {...UserController.store.form()}
                options={{ preserveScroll: true }}
                resetOnSuccess
                onSuccess={onClose}
                className="grid gap-4"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label={t('First name')}
                                htmlFor="staff-first-name"
                                error={errors.first_name}
                            >
                                <Input
                                    id="staff-first-name"
                                    name="first_name"
                                    required
                                />
                            </Field>
                            <Field
                                label={t('Last name')}
                                htmlFor="staff-last-name"
                                error={errors.last_name}
                            >
                                <Input
                                    id="staff-last-name"
                                    name="last_name"
                                    required
                                />
                            </Field>
                        </div>
                        <Field
                            label={t('Email')}
                            htmlFor="staff-email"
                            error={errors.email}
                        >
                            <Input
                                id="staff-email"
                                name="email"
                                type="email"
                                required
                            />
                        </Field>
                        <Field
                            label={t('Role')}
                            htmlFor="staff-role"
                            hint={t(
                                'Super admins can do everything; the other roles see only their part of the platform.',
                            )}
                        >
                            <select
                                id="staff-role"
                                name="platform_role"
                                className="h-9"
                                defaultValue="operations"
                            >
                                {roles.map((role) => (
                                    <option key={role} value={role}>
                                        {t(roleLabels[role] ?? role)}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <div className="flex justify-end gap-2 border-t pt-4">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={onClose}
                            >
                                {t('Cancel')}
                            </Button>
                            <Button disabled={processing}>
                                {t('Send the invitation')}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </SidePanel>
    );
}

export default function PlatformUsers({
    users,
    filters,
    roles,
    currentUserId,
    canManage,
}: Props) {
    const format = useFormat();
    const [search, setSearch] = useState(filters.search);
    const [inviting, setInviting] = useState(false);

    return (
        <>
            <Head title={t('Users')} />
            <PageBody>
                <PageHeader
                    title={t('Users')}
                    description={t(':count accounts on the platform', {
                        count: users.total,
                    })}
                    actions={
                        canManage && (
                            <Button onClick={() => setInviting(true)}>
                                <UserPlus /> {t('Invite MCS staff')}
                            </Button>
                        )
                    }
                />

                <Section
                    flush
                    title={t('Accounts')}
                    description={t(
                        'Platform staff and hotel accounts. Hotel accounts are added from the hotel page.',
                    )}
                    actions={
                        <SearchBox
                            value={search}
                            onChange={setSearch}
                            onSubmit={() =>
                                router.get(
                                    UserController.index.url({
                                        query: { search },
                                    }),
                                    {},
                                    { preserveState: true },
                                )
                            }
                            placeholder={t('Search by name or email')}
                            label={t('Search users')}
                        />
                    }
                >
                    <DataTable
                        rows={users.data}
                        rowKey={(row) => row.id}
                        empty={t('No account matches this search.')}
                        columns={[
                            {
                                key: 'name',
                                header: t('Name'),
                                cell: (row) => (
                                    <div className="flex items-center gap-3">
                                        <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium">
                                            {initials(row.name)}
                                        </span>
                                        <div className="min-w-0">
                                            <div className="font-medium">
                                                {row.name}
                                            </div>
                                            <div className="truncate text-xs text-muted-foreground">
                                                {row.email}
                                            </div>
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                key: 'role',
                                header: t('Role'),
                                cell: (row) => (
                                    <div className="flex flex-wrap gap-1">
                                        {row.platform_role ? (
                                            <Badge variant="secondary">
                                                {t(
                                                    roleLabels[
                                                        row.platform_role
                                                    ] ?? row.platform_role,
                                                )}
                                            </Badge>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                {t('Hotel staff')}
                                            </span>
                                        )}
                                        {!row.two_factor &&
                                            row.platform_role && (
                                                <Badge variant="outline">
                                                    {t('Two-factor not set up')}
                                                </Badge>
                                            )}
                                    </div>
                                ),
                            },
                            {
                                key: 'hotels',
                                header: t('Hotels'),
                                align: 'right',
                                cell: (row) => row.hotels_count,
                            },
                            {
                                key: 'login',
                                header: t('Last sign-in'),
                                cell: (row) => (
                                    <span className="text-muted-foreground">
                                        {format.date(
                                            row.last_login_at,
                                            t('Never'),
                                        )}
                                    </span>
                                ),
                            },
                            {
                                key: 'status',
                                header: t('Status'),
                                cell: (row) =>
                                    row.deactivated ? (
                                        <Badge variant="destructive">
                                            {t('Deactivated')}
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline">
                                            {t('Active')}
                                        </Badge>
                                    ),
                            },
                            ...(canManage
                                ? [
                                      {
                                          key: 'actions',
                                          header: '',
                                          align: 'right' as const,
                                          cell: (row: Row) =>
                                              row.id !== currentUserId &&
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
                                              )),
                                      },
                                  ]
                                : []),
                        ]}
                    />
                    <Pagination links={users.links} label={t('Pagination')} />
                </Section>
            </PageBody>
            {canManage && (
                <InvitePanel
                    open={inviting}
                    onClose={() => setInviting(false)}
                    roles={roles}
                />
            )}
        </>
    );
}

PlatformUsers.layout = {
    breadcrumbs: [{ title: 'Users', href: UserController.index() }],
};
