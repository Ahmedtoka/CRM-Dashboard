import CommentCard from '@/components/crm/CommentCard.vue';
import type { Capabilities, CommentItem } from '@/types/admin';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/vue3')>();

    return { ...actual, usePage: () => ({ props: { auth: { user: { id: 1, name: 'Admin', role: 'admin', platforms: [] } }, platforms: [] } }) };
});

const caps: Capabilities = {
    facebook: { private_reply: true, hide_comment: true, unhide_comment: false },
    instagram: { private_reply: true, hide_comment: true, unhide_comment: false },
    whatsapp: { private_reply: false, hide_comment: false, unhide_comment: false },
    tiktok: { private_reply: false, hide_comment: true, unhide_comment: true },
};

function comment(over: Partial<CommentItem> = {}): CommentItem {
    return {
        id: 7,
        post: { id: 1, platform: 'tiktok', caption: null, permalink: null, thumbnail_url: null, is_ad: true, ad: { id: '4401', title: 'Abaya video' } },
        customer: { id: 3, name: 'mona', avatar_url: null },
        parent_external_id: null,
        body: 'بكام؟',
        status: 'new',
        intent: null,
        public_reply: null,
        public_replied_at: null,
        replied_by_type: null,
        replied_by_id: null,
        private_reply_sent_at: null,
        conversation_id: null,
        created_at: new Date().toISOString(),
        ...over,
    } as CommentItem;
}

const button = (w: ReturnType<typeof mount>, label: string) => w.findAll('button').find((b) => b.text().includes(label));

describe('CommentCard on TikTok ad comments', () => {
    it('offers «إظهار» on a hidden TikTok comment and emits unhide', async () => {
        const w = mount(CommentCard, { props: { comment: comment({ status: 'hidden' }), capabilities: caps } });

        const unhide = button(w, 'إظهار');
        expect(unhide, 'unhide button').toBeDefined();
        await unhide!.trigger('click');

        expect(w.emitted('action')?.[0]?.[0]).toBe('unhide');
    });

    it('keeps «إخفاء» (no unhide) on a hidden Facebook comment', () => {
        const fb = comment({ status: 'hidden', post: { ...comment().post!, platform: 'facebook' } });
        const w = mount(CommentCard, { props: { comment: fb, capabilities: caps } });

        expect(button(w, 'إظهار')).toBeUndefined();
        expect(button(w, 'إخفاء')?.attributes('disabled')).toBeDefined();
    });

    it('disables reply on a TikTok reply-in-thread and private reply always', () => {
        const w = mount(CommentCard, { props: { comment: comment({ parent_external_id: '7300000000000000001' }), capabilities: caps } });

        expect(button(w, 'رد')?.attributes('disabled')).toBeDefined();
        expect(button(w, 'رد خاص')?.attributes('disabled')).toBeDefined();
        expect(w.text()).toContain('تيك توك لا يسمح برسالة خاصة من التعليق في مصر');
    });

    it('lets a first-level TikTok comment be replied to', () => {
        const w = mount(CommentCard, { props: { comment: comment(), capabilities: caps } });

        expect(button(w, 'رد')?.attributes('disabled')).toBeUndefined();
    });
});
