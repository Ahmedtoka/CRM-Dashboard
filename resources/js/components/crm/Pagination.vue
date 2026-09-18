<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { Paginated } from '@/types/admin';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

defineProps<{ page: Paginated<unknown> }>();

const { t } = useI18n();

const linkClass = 'inline-flex h-8 items-center gap-1 rounded-md border border-border bg-background px-2.5 text-xs hover:bg-muted';
</script>

<template>
    <nav v-if="page.meta && page.meta.last_page > 1" class="flex items-center justify-between gap-2 pt-3 text-xs text-muted-foreground" :aria-label="t('ui.pagination')">
        <span class="tabular-nums">{{ t('ui.page_summary', { from: page.meta.from ?? 0, to: page.meta.to ?? 0, total: page.meta.total }) }}</span>
        <div class="flex gap-1.5">
            <Link v-if="page.links?.prev" :href="page.links.prev" preserve-scroll :class="linkClass">
                <ChevronLeft class="rtl-flip size-3.5" aria-hidden="true" />{{ t('ui.prev') }}
            </Link>
            <Link v-if="page.links?.next" :href="page.links.next" preserve-scroll :class="linkClass">
                {{ t('ui.next') }}<ChevronRight class="rtl-flip size-3.5" aria-hidden="true" />
            </Link>
        </div>
    </nav>
</template>
