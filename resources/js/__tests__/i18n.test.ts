import { describe, expect, it } from 'vite-plus/test';
import { getLocale, setLocale, t } from '@/lib/i18n';

describe('t()', () => {
    it('returns French text when the locale is French', () => {
        setLocale('fr');

        expect(t('Staff')).toBe('Personnel');
        expect(t('Team (:count)', { count: 4 })).toBe('Équipe (4)');
    });

    it('falls back to the key for unknown text and unknown locales', () => {
        setLocale('fr');
        expect(t('First Class Lodge')).toBe('First Class Lodge');

        setLocale('xx');
        expect(getLocale()).toBe('en');
        expect(t('Remove :name from this hotel?', { name: 'Awa' })).toBe(
            'Remove Awa from this hotel?',
        );
    });

    it('replaces longer placeholders first', () => {
        setLocale('en');

        expect(t(':name and :names', { name: 'A', names: 'B' })).toBe(
            'A and B',
        );
    });
});
