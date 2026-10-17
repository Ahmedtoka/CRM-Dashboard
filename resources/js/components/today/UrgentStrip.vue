<script setup lang="ts">
import UrgentList, { type UrgentRow } from '@/components/today/UrgentList.vue';
import { useI18n } from '@/composables/useI18n';
import { urgentText } from '@/lib/today';
import type { UrgentItem } from '@/types/today';
import { CircleDollarSign, Hourglass, MessageCircleWarning, RefreshCw, Rocket, Star, Undo2, type LucideIcon } from 'lucide-vue-next';
import { computed } from 'vue';

/** «يحتاج تدخل الآن» on «النهارده»: the server's urgent items as the redesign's UrgentList (DESIGN §3), most important first. */
const props = defineProps<{ items: UrgentItem[] }>();
const { t, locale } = useI18n();

const ICONS: Record<string, LucideIcon> = {
    overdue_windows: MessageCircleWarning,
    lounge: Hourglass,
    low_ratings: Star,
    unpaid_orders: CircleDollarSign,
    cases_overdue: Undo2,
    ads_sync: RefreshCw,
    launches: Rocket,
};

const rows = computed<UrgentRow[]>(() =>
    props.items.map((i) => {
        const text = urgentText(i, t, locale.value);

        return { key: i.key, label: text.label, detail: text.detail, href: i.href, tone: i.tone, icon: ICONS[i.key] };
    }),
);
</script>

<template>
    <UrgentList :title="t('today.urgent.title')" :rows="rows" :empty="t('today.urgent.empty')" />
</template>
