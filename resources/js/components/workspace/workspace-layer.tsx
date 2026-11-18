import { useEffect } from 'react';
import { useWorkspace } from '@/lib/workspace';
import { SimpleTabs } from './simple-tabs';
import { TourRunner } from './tour-runner';

/**
 * Sprint E pieces that sit over the dashboard: the bottom tabs of simple
 * mode, the guided tours, and larger text (addition 2).
 */
export function WorkspaceLayer() {
    const workspace = useWorkspace();
    const large = workspace?.largeText === true;
    const simple = workspace?.simple === true;

    useEffect(() => {
        document.documentElement.dataset.large = large ? 'true' : 'false';
        document.documentElement.dataset.simple = simple ? 'true' : 'false';
    }, [large, simple]);

    return (
        <>
            <SimpleTabs />
            <TourRunner />
        </>
    );
}
