import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import EmailIdentityController from '@/actions/App/Domains/Messaging/Http/Controllers/EmailIdentityController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';

export type EmailIdentity = {
    sender_name: string | null;
    default_sender_name: string;
    reply_to: string | null;
    reply_to_verified: boolean;
    sending_address: string;
};

/**
 * The hotel's sender name and reply-to address for guest emails.
 */
export function EmailIdentityForm({
    identity,
    canManage,
}: {
    identity: EmailIdentity;
    canManage: boolean;
}) {
    const form = useForm({
        email_sender_name: identity.sender_name ?? '',
        email_reply_to: identity.reply_to ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(EmailIdentityController.update.url(), {
            preserveScroll: true,
        });
    };

    const senderPreview = `${form.data.email_sender_name || identity.default_sender_name} <${identity.sending_address}>`;

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <Heading
                variant="small"
                title={t('Email')}
                description={t(
                    'Guest emails are sent from the platform address with your hotel name. Replies go to your confirmed reply address.',
                )}
            />
            <fieldset
                disabled={!canManage}
                className="grid gap-4 sm:grid-cols-2"
            >
                <div className="grid gap-2">
                    <Label htmlFor="email_sender_name">
                        {t('Sender name')}
                    </Label>
                    <Input
                        id="email_sender_name"
                        value={form.data.email_sender_name}
                        placeholder={identity.default_sender_name}
                        maxLength={120}
                        onChange={(e) =>
                            form.setData('email_sender_name', e.target.value)
                        }
                    />
                    <InputError message={form.errors.email_sender_name} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="email_reply_to">
                        {t('Reply-to address')}
                    </Label>
                    <Input
                        id="email_reply_to"
                        type="email"
                        value={form.data.email_reply_to}
                        onChange={(e) =>
                            form.setData('email_reply_to', e.target.value)
                        }
                    />
                    <InputError message={form.errors.email_reply_to} />
                    {identity.reply_to && (
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            {identity.reply_to_verified ? (
                                <Badge variant="secondary">
                                    {t('Confirmed')}
                                </Badge>
                            ) : (
                                <>
                                    <Badge variant="outline">
                                        {t('Waiting for confirmation')}
                                    </Badge>
                                    {canManage && (
                                        <Button
                                            type="button"
                                            variant="link"
                                            size="sm"
                                            className="h-auto p-0"
                                            onClick={() =>
                                                router.post(
                                                    EmailIdentityController.resend.url(),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('Send the confirmation again')}
                                        </Button>
                                    )}
                                </>
                            )}
                        </div>
                    )}
                </div>
            </fieldset>
            <p className="rounded-lg bg-muted/50 px-3 py-2 text-sm text-muted-foreground">
                {t('Guests see:')} {senderPreview}
            </p>
            {canManage && (
                <div>
                    <Button disabled={form.processing}>
                        {t('Save email settings')}
                    </Button>
                </div>
            )}
        </form>
    );
}
