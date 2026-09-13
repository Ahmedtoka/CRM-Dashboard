<script setup lang="ts">
import CommentCard from '@/components/crm/CommentCard.vue';
import CommentFilters from '@/components/crm/CommentFilters.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import PostGroup from '@/components/crm/PostGroup.vue';
import { useCommentFeed } from '@/composables/useCommentFeed';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Capabilities, CommentFilters as Filters, CommentItem, PostRef } from '@/types/admin';
import type { CursorPage } from '@/types/crm';
import { Head, router } from '@inertiajs/vue3';
import { LoaderCircle, MessagesSquare, WifiOff } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ comments: CursorPage<CommentItem>; filters: Filters; capabilities: Capabilities }>();

const { t } = useI18n();
const filters = ref<Filters>({ ...props.filters });
watch(
    () => props.filters,
    (value) => (filters.value = { ...value }),
);

// The backend has no ad filter; "ads only" narrows the loaded comments client-side.
const adOnly = ref(false);

const { items, nextCursor, loadingMore, busy, live, loadMore, run } = useCommentFeed(() => props.comments, filters);

function applyFilters(next: Filters): void {
    filters.value = next;
    const query = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null));
    router.get('/comments', query, { preserveState: true, preserveScroll: true, replace: true });
}

interface Group {
    key: string;
    post: PostRef | null;
    comments: CommentItem[];
}

// Groups keep feed order (newest comment first), keyed by post.
const groups = computed<Group[]>(() => {
    const map = new Map<string, Group>();
    for (const comment of items.value) {
        if (adOnly.value && !comment.post?.is_ad) continue;
        const key = String(comment.post?.id ?? 'none');
        if (!map.has(key)) map.set(key, { key, post: comment.post, comments: [] });
        map.get(key)!.comments.push(comment);
    }
    return [...map.values()];
});

async function onAction(id: number, action: 'reply' | 'hide' | 'private-reply', text: string | undefined, done: () => void): Promise<void> {
    if (await run(id, action, text)) done();
}

const breadcrumbs = computed(() => [{ title: t('comments.title'), href: '/comments' }]);
</script>

<template>
    <Head :title="t('comments.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <PageHeader :title="t('comments.title')">
                <span v-if="!live" class="inline-flex items-center gap-1 text-2xs text-muted-foreground"><WifiOff class="size-3" aria-hidden="true" />{{ t('alerts.polling') }}</span>
            </PageHeader>

            <div class="grid gap-4 lg:grid-cols-[200px_minmax(0,1fr)]">
                <CommentFilters v-model:ad-only="adOnly" :filters="filters" class="lg:sticky lg:top-4 lg:self-start" @update:filters="applyFilters" />

                <div class="space-y-3">
                    <EmptyState v-if="!groups.length" :icon="MessagesSquare" :title="t('comments.empty')" class="rounded-lg border bg-card" />
                    <PostGroup v-for="group in groups" :key="group.key" :post="group.post" :comments="group.comments" @filter-post="applyFilters({ ...filters, post_id: $event })">
                        <CommentCard
                            v-for="comment in group.comments"
                            :key="comment.id"
                            :comment="comment"
                            :capabilities="capabilities"
                            :busy="busy[comment.id]"
                            @action="(action, text, done) => onAction(comment.id, action, text, done)"
                        />
                    </PostGroup>
                    <div v-if="nextCursor" class="text-center">
                        <button type="button" class="inline-flex items-center gap-1.5 text-xs text-primary hover:underline" :disabled="loadingMore" @click="loadMore">
                            <LoaderCircle v-if="loadingMore" class="size-3 animate-spin" aria-hidden="true" />{{ t('ui.load_more') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
