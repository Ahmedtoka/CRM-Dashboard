import DetailsOverlay from '@/components/crm/DetailsOverlay.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const raw = import.meta.glob(['./ChatThread.vue', '../../pages/Inbox.vue'], { query: '?raw', import: 'default', eager: true }) as Record<string, string>;
// Only the template: the script may name the components in comments.
const chatThread = raw['./ChatThread.vue'].slice(raw['./ChatThread.vue'].indexOf('<template>'));
const inbox = raw['../../pages/Inbox.vue'];

describe('DetailsOverlay', () => {
    it('is a non-modal side panel with a translated close button', async () => {
        const w = mount(DetailsOverlay, { slots: { default: '<p data-panel>panel</p>' } });
        const root = w.find('[data-details-overlay]');
        expect(root.classes()).toEqual(expect.arrayContaining(['absolute', 'inset-y-0', 'end-0']));
        expect(root.classes()).not.toContain('fixed');
        expect(root.classes()).not.toContain('inset-0');
        expect(root.attributes('role')).toBe('complementary');
        expect(root.attributes('aria-modal')).toBeUndefined();
        expect(w.find('[data-panel]').exists()).toBe(true);

        const close = w.find('button[data-details-overlay-close]');
        expect(close.attributes('aria-label')).toBe('إغلاق');
        await close.trigger('click');
        expect(w.emitted('close')).toHaveLength(1);
    });

    it('sits in the thread between the header and the composer, never over them', () => {
        const header = chatThread.indexOf('<ThreadHeader');
        const slot = chatThread.indexOf('<slot name="overlay"');
        const composer = chatThread.indexOf('<Composer');
        expect(header).toBeGreaterThan(-1);
        expect(slot).toBeGreaterThan(header);
        expect(slot).toBeLessThan(chatThread.indexOf('<WindowBanner'));
        expect(slot).toBeLessThan(composer);

        // The inbox renders it only through that slot, not over the whole grid.
        expect(inbox).toMatch(/<template #overlay>\s*<DetailsOverlay/);
        expect(inbox.match(/<DetailsOverlay\b/g)).toHaveLength(1);
        expect(inbox).not.toContain('data-details-overlay');
    });
});
