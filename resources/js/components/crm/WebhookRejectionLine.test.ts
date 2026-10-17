import WebhookRejectionLine from '@/components/crm/WebhookRejectionLine.vue';
import { setCurrentLocale } from '@/composables/useI18n';
import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it } from 'vitest';

beforeAll(() => setCurrentLocale('ar'));

describe('WebhookRejectionLine', () => {
    it('says how many webhooks were refused and which secret to check', () => {
        const w = mount(WebhookRejectionLine, {
            props: { summary: { count: 7, last_at: '2026-10-17T08:00:00Z', last_reason: 'bad_hmac', hours: 24 }, secret: 'api_secret' },
        });
        const text = w.find('[data-testid="webhook-rejections"]').text();
        expect(text).toContain('آخر ٢٤ ساعة: ٧ رسالة اترفضت بسبب التوقيع — راجع API secret');
        expect(text).toContain('آخر رفض');
    });

    it('stays hidden when nothing was refused', () => {
        const w = mount(WebhookRejectionLine, { props: { summary: { count: 0, last_at: null, last_reason: null, hours: 24 }, secret: 'app_secret' } });
        expect(w.find('[data-testid="webhook-rejections"]').exists()).toBe(false);
    });
});
