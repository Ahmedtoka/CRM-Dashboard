<script setup lang="ts">
import AdSourceChip from '@/components/crm/AdSourceChip.vue';
import IconAction from '@/components/crm/IconAction.vue';
import { useI18n } from '@/composables/useI18n';
import type { OrderAdSource } from '@/types/crm';
import { ExternalLink } from 'lucide-vue-next';

/**
 * The order's source (fresh-orders F5): the ad chip; for users with ads access a button that opens the AdDrawer
 * (which carries «افتح في Ads Manager»), and the direct «افتح في ميتا» link for Meta ads.
 */
const props = withDefaults(defineProps<{ source: OrderAdSource | null | undefined; canOpenAds?: boolean }>(), { canOpenAds: false });
const emit = defineEmits<{ open: [id: number] }>();
const { t } = useI18n();
</script>

<template>
    <span class="inline-flex max-w-full items-center gap-1.5">
        <button
            v-if="props.source && canOpenAds"
            type="button"
            class="inline-flex min-h-8 min-w-0 items-center rounded-md px-1 hover:bg-muted"
            :aria-label="`${t('ordersHub.open_ad')}: ${props.source.name ?? ''}`"
            data-open-ad
            @click.stop="emit('open', props.source.id)"
        >
            <AdSourceChip :source="props.source" />
        </button>
        <AdSourceChip v-else :source="props.source ?? null" />
        <IconAction
            v-if="props.source?.manager_url && canOpenAds"
            :href="props.source.manager_url"
            external
            :icon="ExternalLink"
            :label="t('ordersHub.open_meta')"
            variant="primary"
            size="sm"
            data-open-meta
            @click.stop
        />
    </span>
</template>
