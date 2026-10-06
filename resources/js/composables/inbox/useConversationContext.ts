import { useApi } from '@/composables/useApi';
import type { ConversationContext, OutcomeState } from '@/types/crm';
import { ref, watch, type ComputedRef, type InjectionKey, type Ref } from 'vue';

/**
 * The open chat's outcome state, provided by the inbox to the close menus. `null` = not known yet
 * (the context is loading): the menus show the outcome row as loading and send nothing.
 */
export const INBOX_OUTCOME: InjectionKey<ComputedRef<OutcomeState | null>> = Symbol('inbox-outcome');

/** A failed load: nothing automatic is known, she picks (the server still records `ordered` first). */
const UNKNOWN: ConversationContext = { outcome: { current: null, source: null, auto: null }, handover: null, ad: null };

/**
 * GET /inbox/conversations/{id}/context (control room S3): the outcome state, the bot's digest and
 * the ad block, loaded beside the thread (never inside its 15-query payload). A late answer for a
 * chat she already left is dropped.
 */
export function useConversationContext(id: Ref<number | null>) {
    const api = useApi();
    const context = ref<ConversationContext | null>(null);
    const loading = ref(false);
    let seq = 0;

    async function reload(): Promise<void> {
        const current = id.value;
        const mine = ++seq;
        if (current === null) {
            context.value = null;
            loading.value = false;

            return;
        }
        loading.value = true;
        try {
            const { data } = await api.get<{ data: ConversationContext }>(`/inbox/conversations/${current}/context`, { silent: true });
            if (mine === seq) context.value = data.data;
        } catch {
            if (mine === seq) context.value = UNKNOWN;
        } finally {
            if (mine === seq) loading.value = false;
        }
    }

    /** An order was just placed in this chat: the close menu locks to «اتعمل أوردر» before the reload lands. */
    function markOrdered(): void {
        if (context.value) context.value = { ...context.value, outcome: { ...context.value.outcome, auto: 'ordered' } };
    }

    watch(
        id,
        () => {
            context.value = null;
            void reload();
        },
        { immediate: true },
    );

    return { context, loading, reload, markOrdered };
}
