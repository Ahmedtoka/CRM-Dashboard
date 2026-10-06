<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { Megaphone } from 'lucide-vue-next';
import { computed } from 'vue';

/** The ad an order or a chat came from, in one compact line (C 5 #2); «مباشر» without one. */
const props = defineProps<{ source: { name: string | null; thumbnail_url: string | null; campaign?: string | null } | null }>();
const { t } = useI18n();
const label = computed(() => (props.source ? [props.source.name, props.source.campaign].filter(Boolean).join(' · ') : t('orders.ad_source.direct')));
</script>

<template>
    <span v-if="source" class="inline-flex max-w-[14rem] items-center gap-1.5 text-xs" :aria-label="label" data-ad-source>
        <img v-if="source.thumbnail_url" :src="source.thumbnail_url" alt="" class="size-5 shrink-0 rounded object-cover" loading="lazy" />
        <Megaphone v-else class="size-3.5 shrink-0 text-amber-600 dark:text-amber-300" aria-hidden="true" />
        <span class="truncate" dir="auto">{{ source.name }}</span>
    </span>
    <span v-else class="text-xs text-muted-foreground">{{ t('orders.ad_source.direct') }}</span>
</template>
