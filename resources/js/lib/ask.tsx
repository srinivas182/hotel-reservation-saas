import { useState } from 'react';
import type { FormEvent } from 'react';
import { createRoot } from 'react-dom/client';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

/**
 * 0.38.0: in-page dialogs instead of the browser's prompt() and confirm().
 * Browsers can block or hide those pop-ups, and then the button seemed to
 * do nothing. These dialogs belong to the page, follow the app's design and
 * work the same on phones.
 *
 *     const minutes = await askText({ title: t('Ready in how many minutes?'), choices: ['10', '20'] });
 *     if (await askConfirm({ title: t('Mark as no-show?'), destructive: true })) { … }
 */
type TextOptions = {
    title: string;
    description?: string;
    label?: string;
    defaultValue?: string;
    /** An empty answer is refused (the dialog stays open). */
    required?: boolean;
    /** Quick answers shown as buttons (e.g. minutes). */
    choices?: string[];
    inputType?: 'text' | 'number' | 'date';
    confirmLabel?: string;
};

type ConfirmOptions = {
    title: string;
    description?: string;
    confirmLabel?: string;
    destructive?: boolean;
};

function mount<T>(
    render: (done: (value: T) => void) => React.ReactElement,
): Promise<T> {
    return new Promise<T>((resolve) => {
        const host = document.createElement('div');
        document.body.appendChild(host);
        const root = createRoot(host);
        const done = (value: T) => {
            resolve(value);
            // Let the closing animation finish before removing the dialog.
            window.setTimeout(() => {
                root.unmount();
                host.remove();
            }, 200);
        };
        root.render(render(done));
    });
}

function TextDialog({
    options,
    done,
}: {
    options: TextOptions;
    done: (value: string | null) => void;
}) {
    const [open, setOpen] = useState(true);
    const [value, setValue] = useState(options.defaultValue ?? '');
    const close = (result: string | null) => {
        setOpen(false);
        done(result);
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (options.required && value.trim() === '') {
            return;
        }
        close(value.trim());
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && close(null)}>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>{options.title}</DialogTitle>
                        {options.description && (
                            <DialogDescription>
                                {options.description}
                            </DialogDescription>
                        )}
                    </DialogHeader>
                    {options.choices && (
                        <div className="flex flex-wrap gap-2">
                            {options.choices.map((choice) => (
                                <Button
                                    key={choice}
                                    type="button"
                                    size="sm"
                                    variant={
                                        value === choice ? 'default' : 'outline'
                                    }
                                    onClick={() => setValue(choice)}
                                >
                                    {choice}
                                </Button>
                            ))}
                        </div>
                    )}
                    <Input
                        autoFocus
                        type={options.inputType ?? 'text'}
                        aria-label={options.label ?? options.title}
                        placeholder={options.label}
                        required={options.required}
                        value={value}
                        onChange={(e) => setValue(e.target.value)}
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => close(null)}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button type="submit">
                            {options.confirmLabel ?? t('OK')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ConfirmDialog({
    options,
    done,
}: {
    options: ConfirmOptions;
    done: (value: boolean) => void;
}) {
    const [open, setOpen] = useState(true);
    const close = (result: boolean) => {
        setOpen(false);
        done(result);
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && close(false)}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{options.title}</DialogTitle>
                    {options.description && (
                        <DialogDescription>
                            {options.description}
                        </DialogDescription>
                    )}
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" onClick={() => close(false)}>
                        {t('Cancel')}
                    </Button>
                    <Button
                        autoFocus
                        variant={
                            options.destructive ? 'destructive' : 'default'
                        }
                        onClick={() => close(true)}
                    >
                        {options.confirmLabel ?? t('Confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** The text typed, or null when the dialog was closed. */
export function askText(options: TextOptions): Promise<string | null> {
    return mount<string | null>((done) => (
        <TextDialog options={options} done={done} />
    ));
}

/** True when the person confirmed. */
export function askConfirm(options: ConfirmOptions | string): Promise<boolean> {
    const resolved = typeof options === 'string' ? { title: options } : options;

    return mount<boolean>((done) => (
        <ConfirmDialog options={resolved} done={done} />
    ));
}
