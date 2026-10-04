<script setup lang="ts">
/**
 * The paused ads published from one material: status chip, error text, and (Meta) an "Open in Ads Manager" link.
 * Polls while any row is still moving (not done / error) and the tab is visible; `refresh()` reloads on demand.
 */
import PlatformChip from '@/components/ads/PlatformChip.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useVisiblePoll } from '@/composables/useVisiblePoll';
import { formatClock, formatDate } from '@/lib/format';
import { safeUrl } from '@/lib/ads';
import type { AdPublicationRow, PublicationStatus } from '@/types/ads';
import { ExternalLink, LoaderCircle } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

const props = defineProps<{ materialId: number }>();
const emit = defineEmits<{ loaded: [rows: AdPublicationRow[]] }>();

const api = useApi();
const { t, locale } = useI18n();

const rows = ref<AdPublicationRow[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const moving = computed(() => rows.value.some((r) => r.status !== 'done' && r.status !== 'error'));

async function refresh(silent = false): Promise<void> {
    try {
        const { data } = await api.get<{ data: AdPublicationRow[] }>(`/ads/materials/${props.materialId}/publications`, { silent });
        rows.value = data.data;
        error.value = null;
        emit('loaded', rows.value);
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        loading.value = false;
    }
}

onMounted(() => void refresh());
useVisiblePoll(() => {
    if (moving.value) void refresh(true);
}, 5000);
defineExpose({ refresh: () => refresh(true) });

const tone = (s: PublicationStatus) => (s === 'done' ? 'positive' : s === 'error' ? 'negative' : 'info');
</script>

<template>
    <div class="space-y-2">
        <p v-if="loading" class="flex items-center gap-2 text-xs text-muted-foreground">
            <LoaderCircle class="size-3.5 animate-spin" aria-hidden="true" />{{ t('ads.publish.loading') }}
        </p>
        <p v-else-if="error" role="alert" class="text-xs text-destructive">{{ error }}</p>
        <p v-else-if="!rows.length" class="text-xs text-muted-foreground">{{ t('ads.publish.none') }}</p>
        <ul v-else class="divide-y divide-border/60 rounded-md border border-border/60">
            <li v-for="r in rows" :key="r.id" class="flex flex-wrap items-start gap-x-3 gap-y-1 px-3 py-2 text-xs">
                <div class="min-w-0 flex-1 space-y-0.5">
                    <p class="flex flex-wrap items-center gap-1.5">
                        <span class="font-semibold" dir="ltr">{{ r.ad_name }}</span>
                        <PlatformChip :platform="r.platform" size="xs" />
                    </p>
                    <p class="truncate text-2xs text-muted-foreground" dir="auto">
                        {{ [r.account, r.campaign, r.adset].filter(Boolean).join(' / ') }}
                    </p>
                    <p v-if="r.error" role="alert" class="text-2xs text-destructive" dir="auto">{{ r.error }}</p>
                </div>
                <div class="flex flex-col items-end gap-1">
                    <StatusChip :label="t(`ads.publish.status.${r.status}`)" :tone="tone(r.status)" :dot="r.status !== 'done' && r.status !== 'error'" />
                    <span class="text-2xs text-muted-foreground tabular-nums">{{ formatDate(r.created_at, locale) }} {{ formatClock(r.created_at, locale) }}</span>
                    <a
                        v-if="safeUrl(r.manager_url)"
                        :href="safeUrl(r.manager_url) ?? undefined"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 text-2xs text-primary hover:underline"
                    >
                        <ExternalLink class="size-3" aria-hidden="true" />{{ t('ads.publish.open_manager') }}
                    </a>
                </div>
            </li>
        </ul>
    </div>
</template>
