/**
 * Page building blocks (0.32): one consistent way to lay out admin and
 * hotel screens. A page = PageHeader, then Tabs (for long pages), then
 * Sections (title and help on the left, content on the right) holding
 * Fields, DataTables and StatTiles. Records are edited in a SidePanel
 * instead of open forms stacked down the page.
 */
import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    cloneElement,
    isValidElement,
    useEffect,
    useId,
    useState,
} from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';

/** Title, one-line description and the page's main actions on the right. */
export function PageHeader({
    title,
    description,
    actions,
}: {
    title: string;
    description?: string;
    actions?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-4">
            <div className="min-w-0 flex-1 basis-80">
                <Heading title={title} description={description} />
            </div>
            {actions && (
                <div className="flex flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}

/** The page body: a column with even spacing and a readable width. */
export function PageBody({
    children,
    wide = false,
}: {
    children: ReactNode;
    wide?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex w-full flex-col gap-6 p-4 lg:p-6',
                wide ? 'max-w-7xl' : 'max-w-6xl',
            )}
        >
            {children}
        </div>
    );
}

/**
 * A card with a heading row (title, help, actions) and its content. With
 * `aside`, the title and help sit in a column on the left on wide screens
 * (settings pages); otherwise above the content (lists, tables).
 */
export function Section({
    title,
    description,
    actions,
    aside = false,
    flush = false,
    children,
}: {
    title: string;
    description?: string;
    actions?: ReactNode;
    aside?: boolean;
    flush?: boolean;
    children: ReactNode;
}) {
    if (aside) {
        return (
            <section className="grid gap-4 rounded-xl border bg-card p-5 lg:grid-cols-[15rem_1fr] lg:gap-8">
                <div className="min-w-0">
                    <h2 className="font-medium">{title}</h2>
                    {description && (
                        <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                            {description}
                        </p>
                    )}
                    {actions && <div className="mt-3">{actions}</div>}
                </div>
                <div className="min-w-0">{children}</div>
            </section>
        );
    }

    return (
        <section className="overflow-hidden rounded-xl border bg-card">
            <div className="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4">
                <div className="min-w-0">
                    <h2 className="font-medium">{title}</h2>
                    {description && (
                        <p className="mt-1 max-w-2xl text-xs leading-relaxed text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
                {actions && (
                    <div className="flex flex-wrap items-center gap-2">
                        {actions}
                    </div>
                )}
            </div>
            <div className={flush ? '' : 'p-5'}>{children}</div>
        </section>
    );
}

/**
 * A label, its control, an optional hint and the error, always in that
 * order. The label is tied to the control: by `htmlFor`, or, when the child
 * is a single element without an id, by an id given to it here (so screen
 * readers and a click on the label reach the field).
 */
export function Field({
    label,
    hint,
    error,
    className,
    htmlFor,
    children,
}: {
    label: string;
    hint?: string;
    error?: string;
    className?: string;
    htmlFor?: string;
    children: ReactNode;
}) {
    const generated = useId();
    const hintId = `${generated}-hint`;
    let control = children;
    let target = htmlFor;

    if (
        target === undefined &&
        isValidElement<{ id?: string; 'aria-describedby'?: string }>(
            children,
        ) &&
        typeof children.type === 'string' &&
        ['input', 'select', 'textarea'].includes(children.type)
    ) {
        target = children.props.id ?? generated;
        control = cloneElement(children, {
            id: target,
            'aria-describedby': hint ? hintId : undefined,
        });
    } else if (
        target === undefined &&
        isValidElement<{ id?: string; 'aria-describedby'?: string }>(
            children,
        ) &&
        typeof children.type !== 'string' &&
        children.props.id === undefined
    ) {
        // Components such as Input and PasswordInput pass the id on.
        target = generated;
        control = cloneElement(children, {
            id: target,
            'aria-describedby': hint ? hintId : undefined,
        });
    }

    return (
        <div
            className={cn(
                'grid min-w-0 grid-cols-[minmax(0,1fr)] content-start gap-1.5',
                className,
            )}
        >
            <label htmlFor={target} className="text-xs font-medium">
                {label}
            </label>
            {control}
            {hint && (
                <span
                    id={hintId}
                    className="text-xs leading-snug text-muted-foreground"
                >
                    {hint}
                </span>
            )}
            <InputError message={error} />
        </div>
    );
}

/** An on/off switch with its label and an optional line of help. */
export function Switch({
    checked,
    onChange,
    label,
    hint,
    disabled,
}: {
    checked: boolean;
    onChange: (value: boolean) => void;
    label: string;
    hint?: string;
    disabled?: boolean;
}) {
    return (
        <label
            className={cn(
                'flex cursor-pointer items-start gap-3',
                disabled && 'cursor-not-allowed opacity-60',
            )}
        >
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                disabled={disabled}
                onClick={() => onChange(!checked)}
                className={cn(
                    'relative mt-0.5 inline-flex h-5 w-9 shrink-0 items-center rounded-full border transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                    checked ? 'border-brand bg-brand' : 'border-input bg-muted',
                )}
            >
                <span
                    className={cn(
                        'inline-block size-3.5 rounded-full bg-white shadow transition-transform',
                        checked ? 'translate-x-4.5' : 'translate-x-0.5',
                    )}
                />
            </button>
            <span className="grid gap-0.5">
                <span className="text-sm">{label}</span>
                {hint && (
                    <span className="text-xs text-muted-foreground">
                        {hint}
                    </span>
                )}
            </span>
        </label>
    );
}

export type Column<T> = {
    key: string;
    header: string;
    cell: (row: T) => ReactNode;
    align?: 'left' | 'right';
    className?: string;
};

/** A clean table: header row, zebra-free rows, an empty state, a row action. */
export function DataTable<T>({
    columns,
    rows,
    rowKey,
    empty,
    onRowClick,
}: {
    columns: Column<T>[];
    rows: T[];
    rowKey: (row: T) => string | number;
    empty: string;
    onRowClick?: (row: T) => void;
}) {
    if (rows.length === 0) {
        return <EmptyState text={empty} />;
    }

    const [first, ...rest] = columns;
    const labelled = rest.filter((c) => c.header !== '');
    const trailing = rest.filter((c) => c.header === '');

    return (
        <>
            {/* Phones: one card per row, the first column as its title. */}
            <ul className="divide-y text-sm sm:hidden">
                {rows.map((row) => (
                    <li
                        key={rowKey(row)}
                        onClick={onRowClick ? () => onRowClick(row) : undefined}
                        className={cn(
                            'grid gap-2 px-4 py-3',
                            onRowClick && 'cursor-pointer active:bg-muted/40',
                        )}
                    >
                        {first && (
                            <div className="min-w-0">{first.cell(row)}</div>
                        )}
                        {labelled.length > 0 && (
                            <dl className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-x-3 gap-y-1">
                                {labelled.map((c) => (
                                    <div key={c.key} className="contents">
                                        <dt className="text-xs text-muted-foreground">
                                            {c.header}
                                        </dt>
                                        <dd className="min-w-0 text-right [&>*]:justify-end">
                                            {c.cell(row)}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                        {trailing.map((c) => (
                            <div
                                key={c.key}
                                className="flex justify-end empty:hidden [&_svg.ml-auto]:hidden"
                            >
                                {c.cell(row)}
                            </div>
                        ))}
                    </li>
                ))}
            </ul>
            <div className="hidden overflow-x-auto sm:block">
                <table className="w-full min-w-[36rem] text-sm">
                    <thead>
                        <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                            {columns.map((c) => (
                                <th
                                    key={c.key}
                                    scope="col"
                                    className={cn(
                                        'px-5 py-2.5 font-medium whitespace-nowrap',
                                        c.align === 'right' && 'text-right',
                                        c.className,
                                    )}
                                >
                                    {c.header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {rows.map((row) => (
                            <tr
                                key={rowKey(row)}
                                onClick={
                                    onRowClick
                                        ? () => onRowClick(row)
                                        : undefined
                                }
                                className={cn(
                                    'transition-colors',
                                    onRowClick &&
                                        'cursor-pointer hover:bg-muted/40',
                                )}
                            >
                                {columns.map((c) => (
                                    <td
                                        key={c.key}
                                        className={cn(
                                            'px-5 py-3 align-middle',
                                            c.align === 'right' &&
                                                'text-right tabular-nums',
                                            c.className,
                                        )}
                                    >
                                        {c.cell(row)}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    );
}

export function EmptyState({ text }: { text: string }) {
    return (
        <p className="px-5 py-10 text-center text-sm text-muted-foreground">
            {text}
        </p>
    );
}

/** A number with its label, for the top of a page. */
export function StatTile({
    label,
    value,
    hint,
}: {
    label: string;
    value: ReactNode;
    hint?: string;
}) {
    return (
        <div className="rounded-xl border bg-card px-4 py-3">
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="mt-1 font-display text-2xl font-extrabold tracking-tight tabular-nums">
                {value}
            </div>
            {hint && (
                <div className="mt-0.5 text-xs text-muted-foreground">
                    {hint}
                </div>
            )}
        </div>
    );
}

export type Tab = { key: string; label: string; count?: number };

/**
 * Tabs for long pages. The open tab is kept in the address (#tab) so a
 * reload or a shared link opens the same tab.
 */
export function useTabs(
    tabs: Tab[],
    fallback?: string,
): [string, (key: string) => void] {
    const first = fallback ?? tabs[0]?.key ?? '';
    const fromHash = () => {
        const hash =
            typeof window === 'undefined' ? '' : window.location.hash.slice(1);

        return tabs.some((t) => t.key === hash) ? hash : first;
    };
    const [active, setActive] = useState<string>(fromHash);

    useEffect(() => {
        const onHash = () => setActive(fromHash());
        window.addEventListener('hashchange', onHash);

        return () => window.removeEventListener('hashchange', onHash);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const select = (key: string) => {
        setActive(key);
        window.history.replaceState(null, '', `#${key}`);
    };

    return [active, select];
}

export function Tabs({
    tabs,
    active,
    onSelect,
}: {
    tabs: Tab[];
    active: string;
    onSelect: (key: string) => void;
}) {
    return (
        <div
            role="tablist"
            className="-mb-2 flex gap-1 overflow-x-auto border-b"
        >
            {tabs.map((tab) => (
                <button
                    key={tab.key}
                    type="button"
                    role="tab"
                    aria-selected={active === tab.key}
                    onClick={() => onSelect(tab.key)}
                    className={cn(
                        '-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm whitespace-nowrap transition-colors',
                        active === tab.key
                            ? 'border-brand font-medium text-foreground'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    )}
                >
                    {tab.label}
                    {tab.count !== undefined && (
                        <span className="rounded-full bg-muted px-1.5 text-xs text-muted-foreground tabular-nums">
                            {tab.count}
                        </span>
                    )}
                </button>
            ))}
        </div>
    );
}

/** A panel that slides in from the right to create or edit one record. */
export function SidePanel({
    open,
    onClose,
    title,
    description,
    children,
    footer,
    wide = false,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    children: ReactNode;
    footer?: ReactNode;
    wide?: boolean;
}) {
    return (
        <Sheet open={open} onOpenChange={(o) => !o && onClose()}>
            <SheetContent
                className={cn(
                    'w-full gap-0',
                    wide ? 'sm:max-w-3xl' : 'sm:max-w-xl',
                )}
            >
                <SheetHeader className="border-b px-6 py-4">
                    <SheetTitle>{title}</SheetTitle>
                    {description && (
                        <SheetDescription>{description}</SheetDescription>
                    )}
                </SheetHeader>
                <div className="flex-1 overflow-y-auto px-6 py-5">
                    {children}
                </div>
                {footer && (
                    <div className="flex items-center justify-end gap-2 border-t bg-muted/30 px-6 py-3">
                        {footer}
                    </div>
                )}
            </SheetContent>
        </Sheet>
    );
}

/** A labelled group inside a side panel or a section. */
export function FieldGroup({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <fieldset className="grid gap-4 border-b pb-6 last:border-b-0 last:pb-0">
            <div>
                <legend className="text-sm font-medium">{title}</legend>
                {description && (
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {children}
        </fieldset>
    );
}

/** Currencies with no decimals: prices are typed and stored in whole units. */
const NO_DECIMALS = [
    'XOF',
    'XAF',
    'GNF',
    'RWF',
    'UGX',
    'BIF',
    'KMF',
    'MGA',
    'JPY',
    'KRW',
    'VND',
    'CLP',
    'PYG',
    'DJF',
    'XPF',
    'VUV',
];

export const minorFactor = (currency: string) =>
    NO_DECIMALS.includes(currency.toUpperCase()) ? 1 : 100;

/** A stored amount (minor units) as people read it: 25 000 F CFA, 39.00 €. */
export function formatMoney(minor: number, currency: string, locale?: string) {
    try {
        return new Intl.NumberFormat(locale, {
            style: 'currency',
            currency,
            maximumFractionDigits: minorFactor(currency) === 1 ? 0 : 2,
        }).format(minor / minorFactor(currency));
    } catch {
        return `${minor / minorFactor(currency)} ${currency}`;
    }
}

/** Several choices as pills that toggle on and off (instead of a multi-select box). */
export function ChipSelect({
    options,
    value,
    onChange,
    label,
}: {
    options: { value: string; label: string }[];
    value: string[];
    onChange: (value: string[]) => void;
    label: string;
}) {
    return (
        <div role="group" aria-label={label} className="flex flex-wrap gap-2">
            {options.map((o) => {
                const on = value.includes(o.value);

                return (
                    <button
                        key={o.value}
                        type="button"
                        aria-pressed={on}
                        onClick={() =>
                            onChange(
                                on
                                    ? value.filter((x) => x !== o.value)
                                    : [...value, o.value],
                            )
                        }
                        className={cn(
                            'rounded-full border px-3 py-1 text-xs transition-colors',
                            on
                                ? 'border-brand bg-brand text-white'
                                : 'hover:bg-muted',
                        )}
                    >
                        {o.label}
                    </button>
                );
            })}
        </div>
    );
}

/** One choice out of a few, as cards with a line of help each. */
export function ChoiceCards({
    options,
    value,
    onChange,
    label,
}: {
    options: { value: string; label: string; hint?: string }[];
    value: string;
    onChange: (value: string) => void;
    label: string;
}) {
    return (
        <div role="radiogroup" aria-label={label} className="grid gap-2">
            {options.map((o) => (
                <button
                    key={o.value}
                    type="button"
                    role="radio"
                    aria-checked={value === o.value}
                    onClick={() => onChange(o.value)}
                    className={cn(
                        'flex items-start gap-3 rounded-lg border px-4 py-3 text-left transition-colors',
                        value === o.value
                            ? 'border-brand bg-brand/5 ring-1 ring-brand'
                            : 'hover:bg-muted/50',
                    )}
                >
                    <span
                        className={cn(
                            'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border',
                            value === o.value && 'border-brand',
                        )}
                    >
                        {value === o.value && (
                            <span className="size-2 rounded-full bg-brand" />
                        )}
                    </span>
                    <span className="grid gap-0.5">
                        <span className="text-sm font-medium">{o.label}</span>
                        {o.hint && (
                            <span className="text-xs text-muted-foreground">
                                {o.hint}
                            </span>
                        )}
                    </span>
                </button>
            ))}
        </div>
    );
}

export type PageLink = { url: string | null; label: string; active: boolean };

/** Page numbers under a table (Laravel paginator links). */
export function Pagination({
    links,
    label,
}: {
    links: PageLink[];
    label: string;
}) {
    if (links.length <= 3) {
        return null;
    }

    return (
        <nav
            aria-label={label}
            className="flex flex-wrap justify-end gap-1 border-t px-5 py-3"
        >
            {links.map((link, index) => (
                <button
                    key={index}
                    type="button"
                    disabled={link.url === null}
                    aria-current={link.active ? 'page' : undefined}
                    onClick={() =>
                        link.url &&
                        router.get(link.url, {}, { preserveState: true })
                    }
                    className={cn(
                        'min-w-8 rounded-md border px-2.5 py-1 text-xs transition-colors disabled:opacity-40',
                        link.active
                            ? 'border-brand bg-brand text-white'
                            : 'bg-card hover:bg-muted',
                    )}
                    dangerouslySetInnerHTML={{ __html: link.label }}
                />
            ))}
        </nav>
    );
}

/** A search field with its icon; submits on Enter. */
export function SearchBox({
    value,
    onChange,
    onSubmit,
    placeholder,
    label,
}: {
    value: string;
    onChange: (value: string) => void;
    onSubmit: () => void;
    placeholder: string;
    label: string;
}) {
    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                onSubmit();
            }}
            className="relative w-full sm:w-72"
        >
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
                type="search"
                className="pl-9"
                placeholder={placeholder}
                aria-label={label}
                value={value}
                onChange={(e) => onChange(e.target.value)}
            />
        </form>
    );
}
