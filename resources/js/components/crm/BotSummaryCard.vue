<script setup lang="ts">
import { Card } from '@/components/ui/card';
import { useI18n } from '@/composables/useI18n';
import type { HandoverDigest } from '@/types/crm';
import { Bot } from 'lucide-vue-next';
import { computed } from 'vue';

/** Every line the bot collected before the handover (C 2.1, G3): visible, never hover-only. */
const props = defineProps<{ digest: HandoverDigest }>();
const { t } = useI18n();

const rows = computed(() => {
    const d = props.digest;
    const out: { label: string; value: string }[] = [];
    const add = (key: string, value: string | null | undefined) => {
        if (value && value.trim() !== '') out.push({ label: t(`inbox.bot_summary.${key}`), value });
    };
    add('reason', d.reason);
    add('category', d.category);
    add('topic', d.topic);
    add('order', d.order_number ? `#${d.order_number}` : null);
    add('products', d.products.join('، '));
    add('sizes', d.sizes.join('، '));
    add('colors', d.colors.join('، '));
    add('governorate', d.governorate);
    for (const line of d.lines) out.push({ label: line.label ?? '•', value: line.value });
    add('last_message', d.last_message ? `«${d.last_message}»` : null);

    return out;
});
</script>

<template>
    <Card class="p-4" data-bot-summary-card>
        <h3 class="mb-2 flex items-center gap-1.5 text-sm font-bold">
            <Bot class="size-4 text-muted-foreground" aria-hidden="true" />{{ t('inbox.bot_summary.title') }}
        </h3>
        <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-xs">
            <template v-for="(row, index) in rows" :key="index">
                <dt class="text-muted-foreground">{{ row.label }}</dt>
                <dd class="min-w-0 break-words" dir="auto">{{ row.value }}</dd>
            </template>
        </dl>
    </Card>
</template>
