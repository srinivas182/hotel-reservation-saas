import { router, usePage } from '@inertiajs/react';

/** The dashboard choices of the signed-in person (Sprint E). */
export type WorkspaceProps = {
    simple: boolean;
    mode: 'simple' | 'full' | null;
    largeText: boolean;
    toursDone: string[];
    memberSince: string | null;
    viewAs: { id: number; name: string } | null;
    canViewAs: boolean;
};

export function useWorkspace(): WorkspaceProps | null {
    return (
        (usePage().props as { workspace?: WorkspaceProps | null }).workspace ??
        null
    );
}

const quiet = { preserveScroll: true, preserveState: true } as const;

export function setMode(mode: 'simple' | 'full' | 'default'): void {
    router.post('/hotel/workspace/mode', { mode }, quiet);
}

export function setLargeText(on: boolean): void {
    router.post('/hotel/workspace/large-text', { large_text: on }, quiet);
}

export function finishTour(key: string): void {
    router.post('/hotel/workspace/tours', { key }, quiet);
}

export function replayTour(): void {
    router.post('/hotel/workspace/tours/replay', {}, quiet);
}

export function stopViewAs(): void {
    router.delete('/hotel/workspace/view-as', quiet);
}

/** Opens the help panel of the current screen (Sprint 17). */
export function openHelp(): void {
    window.dispatchEvent(new CustomEvent('open-help'));
}
