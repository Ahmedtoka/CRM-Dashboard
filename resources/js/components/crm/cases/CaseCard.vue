<script setup lang="ts">
import { Card } from '@/components/ui/card';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/format';
import type { SupportCase } from '@/types/crm';
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import CaseSummarySections from './CaseSummarySections.vue';

const props = defineProps<{ supportCase: SupportCase }>();
const emit = defineEmits<{ updated: [supportCase: SupportCase] }>();

const { t, locale, dir } = useI18n();
const api = useApi();
const saving = ref(false);
const error = ref<string | null>(null);

function viewAllHref(): string {
    const term = props.supportCase.order_number || props.supportCase.customer?.phone;
    return term ? `/cases?q=${encodeURIComponent(term)}` : '/cases';
}

async function onStatusChange(event: Event): Promise<void> {
    const status = (event.target as HTMLSelectElement).value;
    saving.value = true;
    error.value = null;
    try {
        const { data } = await api.patch<{ data: SupportCase }>(`/cases/${props.supportCase.id}`, { status });
        emit('updated', data.data);
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        saving.value = false;
    }
}

const selectClass = 'h-7 rounded-md border border-input bg-background px-1.5 text-2xs';
</script>

<template>
    <Card class="space-y-2 p-3 text-xs">
        <div class="flex flex-wrap items-center justify-between gap-1.5">
            <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                <span class="font-semibold" :dir="dir">{{ supportCase.summary_header }}</span>
            </div>
            <select
                :value="supportCase.status"
                :disabled="saving"
                :class="selectClass"
                :aria-label="t('cases.columns.status')"
                @change="onStatusChange"
            >
                <option v-for="s in ['new', 'in_progress', 'closed']" :key="s" :value="s">{{ t(`cases.tabs.${s}`) }}</option>
            </select>
        </div>

        <CaseSummarySections :sections="supportCase.summary_sections" compact />

        <div v-if="supportCase.photos.length" class="flex flex-wrap gap-1.5">
            <a v-for="photo in supportCase.photos" :key="photo.id" :href="photo.url" target="_blank" rel="noopener">
                <img :src="photo.url" class="size-12 rounded-md border border-border object-cover" alt="" />
            </a>
        </div>

        <p v-if="error" role="alert" class="text-2xs text-destructive">{{ error }}</p>

        <div class="flex items-center justify-between text-2xs text-muted-foreground">
            <span class="tabular-nums">{{ formatDateTime(supportCase.created_at, locale) }}</span>
            <Link :href="viewAllHref()" class="text-primary hover:underline">{{ t('cases.view_all') }}</Link>
        </div>
    </Card>
</template>
