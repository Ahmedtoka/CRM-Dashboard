import MetaSetupHint from '@/components/crm/integrations/MetaSetupHint.vue';
import { setCurrentLocale } from '@/composables/useI18n';
import { TYPE_KEY } from '@/composables/useNotifications';
import ar from '@/i18n/ar';
import en from '@/i18n/en';
import { mount } from '@vue/test-utils';
import { afterAll, describe, expect, it } from 'vitest';

afterAll(() => setCurrentLocale('ar'));

const props = {
    object: 'page',
    callbackUrl: 'https://crm.example.com/webhooks/facebook',
    verifyToken: 'tok',
    fields: ['messages'],
    permissions: ['pages_messaging'],
};

describe('MetaSetupHint verify token warning (audit A2)', () => {
    it('shows nothing extra when the token is usable', () => {
        const wrapper = mount(MetaSetupHint, { props: { ...props, verifyTokenProblem: null } });
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    });

    it.each(['missing', 'default', 'too_short'] as const)('warns, outside the fold, when the token is %s', (problem) => {
        setCurrentLocale('en');
        const wrapper = mount(MetaSetupHint, { props: { ...props, verifyTokenProblem: problem } });
        const alert = wrapper.find('[role="alert"]');
        expect(alert.exists()).toBe(true);
        expect(alert.text()).toContain('META_VERIFY_TOKEN');
        expect(alert.element.closest('details')).toBeNull();
    });

    it.each(['ar', 'en'] as const)('has every warning and the deletion-review bell strings in %s', (locale) => {
        const dict = locale === 'ar' ? ar : en;
        const problems = dict.settings.integrations.meta_setup.verify_token_problem as Record<string, string>;
        for (const key of ['missing', 'default', 'too_short']) expect(typeof problems[key], `${locale}: ${key}`).toBe('string');
        const notifications = dict.notifications as unknown as { legal_deletion_review_item: string; types: Record<string, string> };
        expect(notifications.legal_deletion_review_item).toContain('{code}');
        expect(typeof notifications.types[TYPE_KEY['legal.deletion_review']]).toBe('string');
    });
});
