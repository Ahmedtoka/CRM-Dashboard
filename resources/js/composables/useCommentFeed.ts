import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useEcho } from '@/composables/useEcho';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import type { CommentFilters, CommentItem, CommentPatch } from '@/types/admin';
import type { CursorPage } from '@/types/crm';
import { onBeforeUnmount, ref, watch, type Ref } from 'vue';

type Action = 'reply' | 'hide' | 'private-reply';

/**
 * Comment feed state: cursor paging over /comments/feed, live CommentUpdated merges on
 * `private-comments` (unknown ids trigger a scoped first-page refresh), polling when offline,
 * and the reply / hide / private reply actions.
 */
export function useCommentFeed(page: () => CursorPage<CommentItem>, filters: Ref<CommentFilters>) {
    const api = useApi();
    const { t } = useI18n();
    const toast = useToast();
    const { echo, live, poll } = useEcho();

    const items = ref<CommentItem[]>([...page().data]);
    const nextCursor = ref<string | null>(page().meta?.next_cursor ?? null);
    const loadingMore = ref(false);
    const busy = ref<Record<number, Action | undefined>>({});

    // A filter change is an Inertia visit; reset from the new server page.
    watch(page, (value) => {
        items.value = [...value.data];
        nextCursor.value = value.meta?.next_cursor ?? null;
    });

    function params(cursor?: string | null): Record<string, string | number> {
        const query: Record<string, string | number> = {};
        for (const [key, value] of Object.entries(filters.value)) {
            if (value !== null && value !== '') query[key] = value as string | number;
        }
        if (cursor) query.cursor = cursor;
        return query;
    }

    async function loadMore(): Promise<void> {
        if (!nextCursor.value || loadingMore.value) return;
        loadingMore.value = true;
        try {
            const { data } = await api.get<CursorPage<CommentItem>>('/comments/feed', { params: params(nextCursor.value) });
            const known = new Set(items.value.map((c) => c.id));
            items.value.push(...data.data.filter((c) => !known.has(c.id)));
            nextCursor.value = data.meta?.next_cursor ?? null;
        } catch (error) {
            toast.push(apiErrorMessage(error, t('common.error')), 'error');
        } finally {
            loadingMore.value = false;
        }
    }

    async function refreshFirstPage(): Promise<void> {
        const { data } = await api.get<CursorPage<CommentItem>>('/comments/feed', { params: params(), silent: true });
        const fresh = new Map(data.data.map((c) => [c.id, c]));
        const merged = items.value.map((c) => fresh.get(c.id) ?? c);
        const known = new Set(merged.map((c) => c.id));
        items.value = [...data.data.filter((c) => !known.has(c.id)), ...merged];
    }

    let refreshTimer: number | undefined;
    function scheduleRefresh(): void {
        window.clearTimeout(refreshTimer);
        refreshTimer = window.setTimeout(() => refreshFirstPage().catch(() => undefined), 600);
    }

    function applyPatch(patch: CommentPatch): void {
        const index = items.value.findIndex((c) => c.id === patch.id);
        if (index === -1) {
            // The payload has no post/customer; fetch through the scoped feed instead.
            scheduleRefresh();
            return;
        }
        const { post_id: _postId, ...fields } = patch;
        items.value[index] = { ...items.value[index], ...fields };
    }

    echo?.private('comments').listen('CommentUpdated', applyPatch);
    poll(refreshFirstPage);

    onBeforeUnmount(() => {
        window.clearTimeout(refreshTimer);
        echo?.leave('comments');
    });

    function replace(comment: CommentItem): void {
        const index = items.value.findIndex((c) => c.id === comment.id);
        if (index !== -1) items.value[index] = comment;
    }

    async function run(id: number, action: Action, text?: string): Promise<boolean> {
        if (busy.value[id]) return false;
        busy.value[id] = action;
        try {
            const { data } = await api.post(`/comments/${id}/${action}`, text !== undefined ? { text } : {});
            replace(action === 'private-reply' ? data.data.comment : data.data);
            const done = { reply: 'comments.replied_done', hide: 'comments.hidden_done', 'private-reply': 'comments.private_done' }[action];
            const conversationId = action === 'private-reply' ? data.data.conversation?.id : null;
            toast.push(t(done), 'success', conversationId ? { href: `/inbox?c=${conversationId}`, label: t('ui.open_conversation') } : undefined);
            return true;
        } catch (error) {
            toast.push(apiErrorMessage(error, t('common.error')), 'error');
            return false;
        } finally {
            busy.value[id] = undefined;
        }
    }

    return { items, nextCursor, loadingMore, busy, live, loadMore, run };
}
