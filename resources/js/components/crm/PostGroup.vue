<script setup lang="ts">
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import type { CommentItem, PostRef } from '@/types/admin';
import { ExternalLink, Filter, ImageOff } from 'lucide-vue-next';
import { formatCount } from '@/lib/format';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ post: PostRef | null; comments: CommentItem[] }>();
const emit = defineEmits<{ filterPost: [postId: number] }>();

const { t, locale } = useI18n();

const newCount = computed(() => props.comments.filter((c) => c.status === 'new').length);

// Post thumbnails are platform CDN links that expire: a failed one shows the neutral placeholder,
// never the browser's broken-image glyph.
const thumbFailed = ref(false);
watch(
    () => props.post?.thumbnail_url,
    () => (thumbFailed.value = false),
);
</script>

<template>
    <section class="grid gap-x-4 gap-y-3 border-b border-line p-4 last:border-b-0 sm:grid-cols-[112px_minmax(0,1fr)]" data-post-group>
        <div class="flex items-start gap-3 sm:block">
            <img
                v-if="post?.thumbnail_url && !thumbFailed"
                :src="post.thumbnail_url"
                alt=""
                class="aspect-[4/5] w-16 shrink-0 rounded-lg object-cover sm:w-full"
                loading="lazy"
                referrerpolicy="no-referrer"
                @error="thumbFailed = true"
            />
            <div v-else class="flex aspect-[4/5] w-16 shrink-0 items-center justify-center rounded-lg bg-sunken text-fg-3 sm:w-full" aria-hidden="true">
                <ImageOff class="size-5" />
            </div>
            <div class="min-w-0 space-y-1.5 sm:mt-2">
                <PlatformBadge :platform="post?.platform" show-label size="xs" />
                <p class="text-xs tabular-nums text-fg-3">
                    {{ t('comments.count', { n: formatCount(comments.length, locale) }) }}
                    <span v-if="newCount" class="font-semibold text-foreground">· {{ t('comments.new_count', { n: formatCount(newCount, locale) }) }}</span>
                </p>
                <div class="flex items-center gap-0.5">
                    <button
                        v-if="post"
                        type="button"
                        class="grid size-8 place-items-center rounded-md text-fg-2 hover:bg-sunken hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :title="t('comments.only_this_post')"
                        :aria-label="t('comments.only_this_post')"
                        @click="emit('filterPost', post.id)"
                    >
                        <Filter class="size-4" aria-hidden="true" />
                    </button>
                    <a
                        v-if="post?.permalink"
                        :href="post.permalink"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="grid size-8 place-items-center rounded-md text-fg-2 hover:bg-sunken hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :title="t('comments.view_post')"
                    >
                        <ExternalLink class="size-4" aria-hidden="true" /><span class="sr-only">{{ t('comments.view_post') }}</span>
                    </a>
                </div>
            </div>
        </div>
        <div class="min-w-0">
            <div class="mb-1 flex flex-wrap items-center gap-1.5">
                <p class="min-w-0 flex-1 basis-48 text-sm font-semibold leading-6 text-foreground [overflow-wrap:anywhere]" dir="auto">
                    {{ post?.caption || t('comments.post_untitled') }}
                </p>
                <StatusChip
                    v-if="post?.is_ad"
                    :label="post.ad?.campaign ? `${t('comments.ad')} · ${post.ad.campaign}` : t('comments.ad')"
                    :title="[post.ad?.title, post.ad?.name, post.ad?.adset].filter(Boolean).join(' · ')"
                    tone="brand"
                    class="max-w-full truncate"
                />
            </div>
            <div class="divide-y divide-line">
                <slot />
            </div>
        </div>
    </section>
</template>
