import ClaimConfirmDialog from '@/components/crm/finish/ClaimConfirmDialog.vue';
import { useComposerShortcuts } from '@/composables/inbox/useComposerShortcuts';
import { claimConfirmed, forgetClaims, rememberClaim } from '@/lib/claimConfirm';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';

function composer(claimFromBot: boolean, conversationId = 9) {
    const emitSend = vi.fn();
    const shortcuts = useComposerShortcuts({
        mode: ref('reply'),
        text: ref('أهلاً'),
        mentions: ref([]),
        disabled: () => false,
        busy: () => false,
        noteBusy: () => false,
        lockHolderName: () => null,
        readyAttachments: () => [],
        quickReplyId: () => null,
        clearUploads: () => undefined,
        afterSend: () => undefined,
        focusEnd: () => undefined,
        emitSend,
        emitSendAndResolve: vi.fn(),
        emitNote: vi.fn(),
        needsClaimConfirm: () => claimFromBot && !claimConfirmed(3, conversationId),
        rememberClaim: () => rememberClaim(3, conversationId),
    });

    return { shortcuts, emitSend };
}

afterEach(() => {
    forgetClaims();
    window.localStorage.clear();
    document.body.innerHTML = '';
});

describe('Q10 claim confirm', () => {
    it('asks before the first reply on a bot chat, sends on «ابعتي واستلمي», then never asks again', () => {
        const first = composer(true);
        first.shortcuts.submit();
        expect(first.shortcuts.claiming.value).toBe(true);
        expect(first.emitSend).not.toHaveBeenCalled();

        first.shortcuts.confirmClaim();
        expect(first.emitSend).toHaveBeenCalledWith('أهلاً', [], null);

        const again = composer(true);
        again.shortcuts.submit();
        expect(again.shortcuts.claiming.value).toBe(false);
        expect(again.emitSend).toHaveBeenCalledOnce();
    });

    it('«لسه» sends nothing and asks again next time; a human chat is never asked', () => {
        const c = composer(true);
        c.shortcuts.submit();
        c.shortcuts.cancelClaim();
        expect(c.emitSend).not.toHaveBeenCalled();
        c.shortcuts.submit();
        expect(c.shortcuts.claiming.value).toBe(true);

        const human = composer(false);
        human.shortcuts.submit();
        expect(human.emitSend).toHaveBeenCalledOnce();
    });

    it('is per conversation, and works with storage blocked', () => {
        const setItem = vi.spyOn(window.localStorage, 'setItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        rememberClaim(3, 9);
        expect(claimConfirmed(3, 9)).toBe(true);
        expect(claimConfirmed(3, 10)).toBe(false);
        expect(claimConfirmed(4, 9)).toBe(false);
        setItem.mockRestore();
    });

    it('the dialog says what happens and names both buttons', async () => {
        const w = mount(ClaimConfirmDialog, { props: { open: true }, attachTo: document.body });
        await flushPromises();
        expect(document.body.textContent).toContain('هتستلمي المحادثة دي من البوت');
        document.body.querySelector<HTMLElement>('[data-claim-ok]')!.click();
        await flushPromises();
        expect(w.emitted('confirm')).toHaveLength(1);
        expect(w.emitted('update:open')?.at(-1)).toEqual([false]);
        w.unmount();
    });
});
