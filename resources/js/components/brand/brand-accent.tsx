import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';

type Palette = {
    light: Record<string, string>;
    dark: Record<string, string>;
};

const css = (p: Palette): string => {
    const decl = (vars: Record<string, string>) =>
        Object.entries(vars)
            .map(([k, v]) => `${k}: ${v};`)
            .join(' ');

    return `html:root { ${decl(p.light)} } html.dark { ${decl(p.dark)} }`;
};

/**
 * Keeps the accent colours in step with the hotel being viewed (Sprint I).
 * The first paint already has them (app.blade.php); this follows a switch
 * of hotel or a new colour without a reload.
 */
export function BrandAccent() {
    const palette = (usePage().props as { palette?: Palette }).palette;

    useEffect(() => {
        if (!palette) {
            return;
        }
        let el = document.getElementById('brand-palette');
        if (!el) {
            el = document.createElement('style');
            el.id = 'brand-palette';
            const nonce = document
                .querySelector<HTMLScriptElement>('script[nonce]')
                ?.getAttribute('nonce');
            if (nonce) {
                el.setAttribute('nonce', nonce);
            }
            document.head.appendChild(el);
        }
        el.textContent = css(palette);
    }, [palette]);

    return null;
}
