import type { Translate } from '@/lib/conversationState';

/**
 * The «تيست» mark of a production load-test chat (2026-10-07) where the board shows a customer's
 * name: the room, the lounge, the panels and the phone layout all read the same «تيست · منى».
 */
export function loadTestName(name: string, isLoadTest: boolean | undefined, t: Translate): string {
    return isLoadTest ? `${t('inbox.load_test_badge')} · ${name}` : name;
}
