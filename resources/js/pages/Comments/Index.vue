<script setup lang="ts">
import CommentCard from '@/components/crm/CommentCard.vue';
import CommentFilters from '@/components/crm/CommentFilters.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import InlineError from '@/components/crm/InlineError.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import PostGroup from '@/components/crm/PostGroup.vue';
import SkeletonList from '@/components/crm/SkeletonList.vue';
import { Button } from '@/components/ui/button';
import { useCommentFeed } from '@/composables/useCommentFeed';
import { useI18n } from '@/composables/useI18n';
import { useUrlFilters } from '@/composables/useUrlFilters';
import { useVisitLoading } from '@/composables/useVisitLoading';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Capabilities, CommentFilters as Filters, CommentItem, PostRef } from '@/types/admin';
import type { CursorPage } from '@/types/crm';
import { Head, router } from '@inertiajs/vue3';
import { MessagesSquare, WifiOff } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ comments: CursorPage<CommentItem>; filters: Filters; capabilities: Capabilities }>();

const { t } = useI18n();
const filters = ref<Filters>({ ...props.filters });
watch(
    () => props.filters,
    (value) => (filters.value = { ...value }),
);

// The backend has no ad filter; "ads only" narrows the loaded comments client-side. It lives in the URL (`ad=1`)
// so a reload or a shared link keeps it.
const { filters: urlFilters, set: setUrl } = useUrlFilters({ ad: false as boolean });
const adOnly = computed({
    get: () => urlFilters.value.ad,
    set: (value: boolean) => setUrl({ ad: value }),
});

const { items, nextCursor, loadingMore, loadError, busy, live, loadMore, run } = useCommentFeed(() => props.comments, filters);
const { loading, track } = useVisitLoading();

function applyFilters(next: Filters): void {
    filters.value = next;
    const query: Record<string, string | number> = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null)) as Record<string, string | number>;
    if (adOnly.value) query.ad = 1;
    router.get('/comments', query, track({ preserveState: true, preserveScroll: true, replace: true }));
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
        <div class="mx-auto w-full max-w-7xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('comments.title')">
                <span v-if="!live" class="inline-flex items-center gap-1 text-2xs text-muted-foreground"><WifiOff class="size-3" aria-hidden="true" />{{ t('alerts.polling') }}</span>
            </PageHeader>

            <div class="space-y-4">
                <CommentFilters v-model:ad-only="adOnly" :filters="filters" class="rounded-lg bg-card p-3 shadow-card" @update:filters="applyFilters" />

                <div class="space-y-3" :aria-busy="loading">
                    <SkeletonList v-if="loading && !groups.length" variant="cards" />
                    <EmptyState v-else-if="!groups.length" :icon="MessagesSquare" :title="t('comments.empty')" class="rounded-lg bg-card shadow-card" />
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
                    <InlineError v-if="loadError" :message="loadError" :retrying="loadingMore" @retry="loadMore" />
                    <div v-else-if="nextCursor" class="text-center">
                        <Button type="button" variant="ghost" size="sm" class="text-primary" :loading="loadingMore" @click="loadMore">{{ t('ui.load_more') }}</Button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
