import { useApi } from '@/composables/useApi';
import type { ChatFunnel } from '@/types/ads';
import { ref, watch, type Ref } from 'vue';

export interface AdFunnelState {
    funnel: Ref<ChatFunnel | null>;
    loading: Ref<boolean>;
    error: Ref<boolean>;
    retry: () => void;
}

/**
 * The open ad's chat funnel for the page's range (GET /ads/chat-funnel), fetched once per drawer open
 * and silently (no global bar: the drawer shows its own skeleton). A late answer for another ad is dropped.
 */
export function useAdFunnel(adId: () => number | null, range: () => { from?: string | null; to?: string | null }): AdFunnelState {
    const api = useApi();
    const funnel = ref<ChatFunnel | null>(null);
    const loading = ref(false);
    const error = ref(false);
    let seq = 0;

    async function load(): Promise<void> {
        const mine = ++seq;
        const id = adId();
        funnel.value = null;
        error.value = false;
        if (id === null) {
            loading.value = false;
            return;
        }
        loading.value = true;
        const { from, to } = range();
        const params: Record<string, unknown> = { ads: [id] };
        if (from) params.from = from;
        if (to) params.to = to;
        try {
            const { data } = await api.get<{ data: Record<string, ChatFunnel> }>('/ads/chat-funnel', { params, silent: true });
            if (mine === seq) funnel.value = data.data[String(id)] ?? null;
        } catch {
            if (mine === seq) error.value = true;
        } finally {
            if (mine === seq) loading.value = false;
        }
    }

    watch(() => [adId(), range().from, range().to] as const, () => void load(), { immediate: true });

    return { funnel, loading, error, retry: () => void load() };
}
