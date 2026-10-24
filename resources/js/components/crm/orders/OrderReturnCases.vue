<script setup lang="ts">
import ReturnLifecycle from '@/components/crm/cases/ReturnLifecycle.vue';
import DetailPanel from '@/components/crm/orders/DetailPanel.vue';
import { useI18n } from '@/composables/useI18n';
import type { SupportCase } from '@/types/crm';
import { Link } from '@inertiajs/vue3';
import { RotateCcw } from 'lucide-vue-next';
import { ref, watch } from 'vue';

/** The order page's «المرتجعات والاستبدال» (round 2026-10-24): each return/exchange case of the order with its lifecycle and actions. */
const props = defineProps<{ cases: SupportCase[] }>();
const emit = defineEmits<{ updated: [] }>();

const { t } = useI18n();

// A local copy so a step shows at once; the page reload replaces it.
const list = ref<SupportCase[]>(props.cases);
watch(
    () => props.cases,
    (value) => (list.value = value),
);

function onUpdated(fresh: SupportCase): void {
    list.value = list.value.map((c) => (c.id === fresh.id ? fresh : c));
    emit('updated');
}
</script>

<template>
    <DetailPanel v-if="list.length" :title="t('return_lifecycle.title')" :icon="RotateCcw" data-order-returns>
        <div class="divide-y divide-line">
            <section v-for="c in list" :key="c.id" class="space-y-2 py-3 first:pt-0 last:pb-0" :data-return-case="c.id">
                <div class="flex flex-wrap items-center gap-2 text-13">
                    <span class="font-semibold">{{ t(`return_lifecycle.kind.${c.return?.is_exchange ? 'exchange' : 'return'}`) }}</span>
                    <Link :href="`/cases?open=${c.id}`" class="text-brand-ink hover:underline">{{ t('return_lifecycle.case', { id: c.id }) }}</Link>
                    <span v-if="c.reason" class="text-fg-2" dir="auto">· {{ c.reason }}</span>
                </div>
                <ReturnLifecycle v-if="c.return" :case-id="c.id" :view="c.return" @updated="onUpdated" />
            </section>
        </div>
    </DetailPanel>
</template>
