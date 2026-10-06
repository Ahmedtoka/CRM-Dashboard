<script setup lang="ts">
/** Campaign → ad set → ad (explorer tree view). Open nodes live in the URL (`open=c:12,s:40`, U 4.4). Desktop only. */
import AdStatusButton from '@/components/ads/AdStatusButton.vue';
import { useI18n } from '@/composables/useI18n';
import { adStatusLabel, formatAdsMoney, formatRoas } from '@/lib/ads';
import type { CampaignNode } from '@/types/ads';
import { ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(defineProps<{ nodes: CampaignNode[]; currency?: string; open: string[]; dataAt?: string | null }>(), { currency: 'EGP', dataAt: null });
const emit = defineEmits<{ 'update:open': [keys: string[]]; openAd: [id: number] }>();
const { t, locale } = useI18n();

const keyOf = (n: CampaignNode) => `${n.level === 'campaign' ? 'c' : n.level === 'adset' ? 's' : 'a'}:${n.id}`;
const rows = computed(() => {
    const out: { node: CampaignNode; depth: number; key: string; path: string }[] = [];
    const walk = (list: CampaignNode[], depth: number, parent: string) => {
        for (const n of list) {
            const key = keyOf(n);
            const path = `${parent}/${key}`;
            out.push({ node: n, depth, key, path });
            if (n.children.length && props.open.includes(key)) walk(n.children, depth + 1, path);
        }
    };
    walk(props.nodes, 0, '');
    return out;
});
function toggle(key: string): void {
    emit('update:open', props.open.includes(key) ? props.open.filter((k) => k !== key) : [...props.open, key]);
}
</script>

<template>
    <div data-table-box class="table-scroll-box scrollbar-thin relative rounded-lg bg-card shadow-card">
        <table class="w-full min-w-[720px] text-xs">
            <thead class="crm-sticky-head bg-card text-2xs font-semibold text-muted-foreground">
                <tr>
                    <th scope="col" class="px-3 py-2 text-start">{{ t('ads.control.col.creative') }}</th>
                    <th scope="col" class="px-3 py-2 text-end">{{ t('ads.control.col.spend') }}</th>
                    <th scope="col" class="px-3 py-2 text-end">{{ t('ads.control.col.return') }}</th>
                    <th scope="col" class="px-3 py-2 text-end">{{ t('ads.control.col.status') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in rows" :key="r.path" class="border-t border-border/60 hover:bg-muted/40">
                    <td class="px-3 py-2">
                        <div class="flex min-w-0 items-center gap-1.5" :style="{ paddingInlineStart: `${r.depth * 20}px` }">
                            <button
                                v-if="r.node.children.length"
                                type="button"
                                :data-test="`toggle-${r.key}`"
                                class="inline-flex size-7 shrink-0 items-center justify-center rounded hover:bg-muted"
                                :aria-expanded="open.includes(r.key)"
                                :aria-label="t(open.includes(r.key) ? 'ads.control.explorer.collapse' : 'ads.control.explorer.expand', { name: r.node.name || t(`ads.campaigns.level_${r.node.level}`) })"
                                @click="toggle(r.key)"
                            >
                                <ChevronRight class="rtl-flip size-3.5 transition-transform" :class="open.includes(r.key) ? 'rotate-90 rtl:-rotate-90' : ''" aria-hidden="true" />
                            </button>
                            <span v-else class="size-7 shrink-0" aria-hidden="true" />
                            <button
                                v-if="r.node.level === 'ad' && r.node.ad_id"
                                type="button"
                                :data-test="`open-ad-${r.node.ad_id}`"
                                class="truncate text-start hover:underline"
                                dir="auto"
                                @click="emit('openAd', r.node.ad_id)"
                            >
                                {{ r.node.name }}
                            </button>
                            <span v-else class="truncate font-medium" dir="auto">{{ r.node.name || '—' }}</span>
                            <span class="shrink-0 text-2xs text-muted-foreground">{{ t(`ads.campaigns.level_${r.node.level}`) }}</span>
                            <span
                                v-if="!r.node.naming_ok && !r.node.placeholder"
                                data-test="naming-bad"
                                class="shrink-0 rounded bg-warning/20 px-1.5 text-2xs"
                                :title="t('ads.control.explorer.naming_hint')"
                            >
                                {{ t('ads.control.explorer.naming_bad') }}
                            </span>
                        </div>
                    </td>
                    <td class="px-3 py-2 text-end tabular-nums">{{ formatAdsMoney(r.node.metrics.spend_tax, locale, currency) }}</td>
                    <td class="px-3 py-2 text-end tabular-nums">
                        <!-- D10: real ROAS is the figure; Meta's own ROAS stays small under it. -->
                        <span data-test="real-roas" class="block text-sm font-semibold"><span class="sr-only">{{ t('ads.control.row.real') }} </span>{{ formatRoas(r.node.metrics.real_roas ?? null, locale) }}</span>
                        <span data-test="meta-roas" class="block text-2xs text-muted-foreground">{{ t('ads.control.row.meta') }} {{ formatRoas(r.node.metrics.roas, locale) }}</span>
                    </td>
                    <td class="px-3 py-2">
                        <div class="flex items-center justify-end gap-2">
                            <span class="whitespace-nowrap text-2xs text-muted-foreground">{{ adStatusLabel(r.node.status, t) }}</span>
                            <AdStatusButton
                                v-if="!r.node.placeholder"
                                :account-id="r.node.account_id"
                                :account="r.node.account"
                                :platform="String(r.node.platform)"
                                :level="r.node.level"
                                :external-id="r.node.external_id"
                                :name="r.node.name"
                                :status="r.node.status"
                                :can-write="r.node.can_write === true"
                                :data-at="dataAt"
                                :currency="currency"
                                :parent-paused="r.node.parent_paused"
                            />
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
