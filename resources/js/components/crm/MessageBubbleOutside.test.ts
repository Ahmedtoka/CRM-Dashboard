import MessageBubble from '@/components/crm/MessageBubble.vue';
import type { Message } from '@/types/crm';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const base: Message = {
    id: 1,
    conversation_id: 9,
    direction: 'out',
    sender_type: 'user',
    user: null,
    body: 'تحت أمرك',
    attachments: [],
    status: 'sent',
    error: null,
    created_at: '2026-10-17T10:00:00Z',
};

describe('MessageBubble — replies sent outside the CRM', () => {
    it('labels a Business Suite reply and names the team', () => {
        const w = mount(MessageBubble, { props: { message: { ...base, sent_outside_crm: true }, platformColor: '#0866ff' } });
        expect(w.find('[data-testid="sent-outside-crm"]').text()).toBe('اتبعتت من برة الـ CRM');
        expect(w.text()).toContain('الفريق');
    });

    it('shows no label on a CRM reply', () => {
        const w = mount(MessageBubble, {
            props: { message: { ...base, user: { id: 2, name: 'سارة', color: '#123456' } }, platformColor: '#0866ff' },
        });
        expect(w.find('[data-testid="sent-outside-crm"]').exists()).toBe(false);
        expect(w.text()).toContain('سارة');
    });
});
