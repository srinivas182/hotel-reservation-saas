import { Form, Head, Link } from '@inertiajs/react';
import AcceptInvitationController from '@/actions/App/Domains/Access/Http/Controllers/AcceptInvitationController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { login, logout } from '@/routes';

type Props = {
    state: 'pending' | 'invalid' | 'expired' | 'accepted' | 'revoked';
    token: string;
    invitation?: { hotel: string; role: string; email: string };
    accountExists?: boolean;
    signedInAs?: string | null;
    signedInAsInvitee?: boolean;
};

const messages: Record<string, string> = {
    invalid:
        'This invitation link is not valid. Check that you copied the whole link.',
    expired: 'This invitation has expired. Ask the hotel to send a new one.',
    accepted: 'This invitation has already been accepted. Sign in to continue.',
    revoked: 'This invitation was cancelled. Ask the hotel if you need access.',
};

export default function InvitationShow({
    state,
    token,
    invitation,
    accountExists,
    signedInAs,
    signedInAsInvitee,
}: Props) {
    if (state !== 'pending' || !invitation) {
        return (
            <>
                <Head title="Invitation" />
                <p className="text-center text-sm text-muted-foreground">
                    {messages[state]}
                </p>
                <div className="mt-6 text-center">
                    <TextLink href={login()}>Go to sign in</TextLink>
                </div>
            </>
        );
    }

    const action = AcceptInvitationController.store.form(token);

    return (
        <>
            <Head title={`Join ${invitation.hotel}`} />
            <p className="mb-6 text-center text-sm text-muted-foreground">
                You are invited to join{' '}
                <strong className="text-foreground">{invitation.hotel}</strong>{' '}
                as{' '}
                <strong className="text-foreground">{invitation.role}</strong>.
            </p>

            {signedInAsInvitee && (
                <Form {...action}>
                    {({ processing }) => (
                        <Button className="w-full" disabled={processing}>
                            Accept invitation
                        </Button>
                    )}
                </Form>
            )}

            {signedInAs && !signedInAsInvitee && (
                <div className="text-center text-sm">
                    <p className="text-muted-foreground">
                        You are signed in as {signedInAs}, but this invitation
                        is for {invitation.email}.
                    </p>
                    <Link
                        href={logout()}
                        method="post"
                        as="button"
                        className="mt-4 underline"
                    >
                        Sign out and continue
                    </Link>
                </div>
            )}

            {!signedInAs && accountExists && (
                <div className="text-center text-sm">
                    <p className="text-muted-foreground">
                        An account already exists for {invitation.email}. Sign
                        in, then open this link again to accept.
                    </p>
                    <div className="mt-4">
                        <TextLink href={login()}>Sign in</TextLink>
                    </div>
                </div>
            )}

            {!signedInAs && !accountExists && (
                <Form
                    {...action}
                    resetOnError={['password', 'password_confirmation']}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    value={invitation.email}
                                    disabled
                                    readOnly
                                />
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="first_name">
                                        First name
                                    </Label>
                                    <Input
                                        id="first_name"
                                        name="first_name"
                                        required
                                        autoComplete="given-name"
                                    />
                                    <InputError message={errors.first_name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="last_name">Last name</Label>
                                    <Input
                                        id="last_name"
                                        name="last_name"
                                        required
                                        autoComplete="family-name"
                                    />
                                    <InputError message={errors.last_name} />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autoComplete="new-password"
                                />
                                <InputError message={errors.password} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Confirm password
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    required
                                    autoComplete="new-password"
                                />
                            </div>
                            <Button className="w-full" disabled={processing}>
                                Create account and join
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </>
    );
}

InvitationShow.layout = {
    title: 'Hotel invitation',
    description: 'Join your team',
};
