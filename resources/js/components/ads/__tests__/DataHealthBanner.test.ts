import DataHealthBanner from '@/components/ads/DataHealthBanner.vue';
import { setCurrentLocale } from '@/composables/useI18n';
import { mount } from '@vue/test-utils';
import { afterAll, describe, expect, it } from 'vitest';

afterAll(() => setCurrentLocale('ar'));

describe('DataHealthBanner (final review C6)', () => {
    it('says the stale threshold from the server, in the locale digits', () => {
        setCurrentLocale('ar');
        const ar = mount(DataHealthBanner, { props: { dataHealth: { reasons: [{ reason: 'stale', accounts: ['Le Voile'], more: 0, hours: 6 }] } } });
        expect(ar.text()).toContain('أكتر من ٦ ساعات');
        expect(ar.text()).not.toContain('3');

        setCurrentLocale('en');
        const en = mount(DataHealthBanner, { props: { dataHealth: { reasons: [{ reason: 'stale', accounts: ['Le Voile'], more: 0, hours: 6 }] } } });
        expect(en.text()).toContain('over 6 hours old');
    });
});
