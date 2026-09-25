<script setup lang="ts">
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import type { CommentItem, PostRef } from '@/types/admin';
import { ExternalLink, Filter, ImageOff } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ post: PostRef | null; comments: CommentItem[] }>();
const emit = defineEmits<{ filterPost: [postId: number] }>();

const { t } = useI18n();

const newCount = computed(() => props.comments.filter((c) => c.status === 'new').length);
</script>

<template>
    <section class="overflow-hidden rounded-lg bg-card shadow-card">
        <header class="flex items-start gap-3 border-b border-border bg-muted/30 p-3">
            <img v-if="post?.thumbnail_url" :src="post.thumbnail_url" alt="" class="size-12 shrink-0 rounded object-cover" loading="lazy" />
            <div v-else class="flex size-12 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground" aria-hidden="true">
                <ImageOff class="size-4" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                    <PlatformBadge :platform="post?.platform" show-label />
                    <StatusChip
                        v-if="post?.is_ad"
                        :label="post.ad?.campaign ? `${t('comments.ad')} · ${post.ad.campaign}` : t('comments.ad')"
                        :title="[post.ad?.title, post.ad?.name, post.ad?.adset].filter(Boolean).join(' · ')"
                        tone="info"
                    />
                    <span class="text-2xs tabular-nums text-muted-foreground">{{ t('comments.count', { n: comments.length }) }}</span>
                    <span v-if="newCount" class="text-2xs font-medium tabular-nums text-foreground">· {{ t('comments.new_count', { n: newCount }) }}</span>
                </div>
                <p class="mt-1 line-clamp-2 text-xs text-foreground" dir="auto">{{ post?.caption || t('comments.post_untitled') }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <button
                    v-if="post"
                    type="button"
                    class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                    :title="t('ui.filters')"
                    :aria-label="t('ui.filters')"
                    @click="emit('filterPost', post.id)"
                >
                    <Filter class="size-3.5" aria-hidden="true" />
                </button>
                <a
                    v-if="post?.permalink"
                    :href="post.permalink"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                    :title="t('comments.view_post')"
                >
                    <ExternalLink class="size-3.5" aria-hidden="true" /><span class="sr-only">{{ t('comments.view_post') }}</span>
                </a>
            </div>
        </header>
        <div class="divide-y divide-border">
            <slot />
        </div>
    </section>
</template>
