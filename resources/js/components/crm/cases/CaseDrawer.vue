<script setup lang="ts">
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { casePriorityTone, caseStatusTone } from '@/lib/caseStatus';
import { formatDateTime, formatMoney } from '@/lib/format';
import type { SupportCase } from '@/types/crm';
import { Link } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import StatusChip from '../StatusChip.vue';
import CaseSummarySections from './CaseSummarySections.vue';

const props = defineProps<{ caseId: number | null; team: { id: number; name: string }[] }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ updated: [supportCase: SupportCase] }>();

const api = useApi();
const { t, locale, dir } = useI18n();

const loading = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);
const item = ref<SupportCase | null>(null);

async function load(id: number): Promise<void> {
    loading.value = true;
    error.value = null;
    item.value = null;
    try {
        const { data } = await api.get<{ data: SupportCase }>(`/cases/${id}`);
        item.value = data.data;
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.caseId,
    (id) => {
        if (id !== null) load(id);
    },
    { immediate: true },
);

async function patch(payload: Record<string, unknown>): Promise<void> {
    if (!item.value) return;
    saving.value = true;
    error.value = null;
    try {
        const { data } = await api.patch<{ data: SupportCase }>(`/cases/${item.value.id}`, payload);
        item.value = data.data;
        emit('updated', data.data);
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        saving.value = false;
    }
}

function onStatusChange(event: Event): void {
    void patch({ status: (event.target as HTMLSelectElement).value });
}

function onAssignedChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    void patch({ assigned_to_id: value ? Number(value) : null });
}

const selectClass = 'h-8 w-full rounded-md border border-input bg-background px-2 text-xs';
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent :side="dir === 'rtl' ? 'left' : 'right'" class="flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-md">
            <SheetHeader class="border-b px-4 py-3 text-start">
                <SheetTitle class="text-sm">{{ item ? t('cases.card_title', { id: item.id }) : t('cases.title') }}</SheetTitle>
                <SheetDescription v-if="item" class="text-xs" dir="rtl">{{ item.summary_header }}</SheetDescription>
            </SheetHeader>

            <div v-if="loading" class="space-y-3 p-4" aria-busy="true">
                <Skeleton class="h-5 w-1/2" />
                <Skeleton class="h-16 w-full" />
                <Skeleton class="h-16 w-full" />
            </div>

            <div v-else-if="error && !item" class="p-4 text-xs text-destructive">{{ error }}</div>

            <div v-else-if="item" class="scrollbar-thin flex-1 space-y-4 overflow-y-auto p-4 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <StatusChip :label="t(`cases.priority.${item.priority}`)" :tone="casePriorityTone[item.priority]" />
                    <StatusChip :label="t(`cases.tabs.${item.status}`)" :tone="caseStatusTone[item.status]" />
                    <StatusChip v-if="item.request_kind" :label="t(`cases.types.${item.request_kind}`)" tone="info" />
                </div>

                <p v-if="item.request_kind && item.reason" class="text-xs">
                    <span class="font-semibold text-muted-foreground">{{ t('cases.reason') }}:</span> {{ item.reason }}
                </p>

                <p class="text-xs text-muted-foreground">
                    {{ t('cases.created_at') }}: <span class="tabular-nums">{{ formatDateTime(item.created_at, locale) }}</span>
                </p>

                <CaseSummarySections :sections="item.summary_sections" />

                <div v-if="item.items?.length" class="space-y-1.5">
                    <h3 class="text-xs font-semibold text-muted-foreground">{{ t('cases.items.title') }}</h3>
                    <div class="overflow-x-auto rounded-md border border-border">
                        <table class="w-full text-xs" dir="rtl">
                            <thead class="bg-muted/60 text-muted-foreground">
                                <tr>
                                    <th scope="col" class="px-2 py-1.5 text-start font-medium">{{ t('cases.items.item') }}</th>
                                    <th scope="col" class="px-2 py-1.5 text-start font-medium">{{ t('cases.items.variant') }}</th>
                                    <th scope="col" class="px-2 py-1.5 text-center font-medium">{{ t('cases.items.qty') }}</th>
                                    <th scope="col" class="px-2 py-1.5 text-end font-medium">{{ t('cases.items.price') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, index) in item.items" :key="index" class="border-t border-border">
                                    <td class="px-2 py-1.5">
                                        <span class="font-medium">{{ row.title }}</span>
                                        <span
                                            v-if="row.exchange_only"
                                            class="ms-1.5 inline-flex rounded-full bg-warning/15 px-1.5 py-px text-2xs font-medium text-foreground ring-1 ring-warning/40"
                                            >{{ t('cases.items.exchange_only') }}</span
                                        >
                                    </td>
                                    <td class="px-2 py-1.5 text-muted-foreground">{{ row.variant ?? '—' }}</td>
                                    <td class="px-2 py-1.5 text-center tabular-nums">{{ row.qty }}</td>
                                    <td class="px-2 py-1.5 text-end tabular-nums">{{ row.price !== null ? formatMoney(row.price, locale) : '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="item.exchange_product" class="space-y-1.5">
                    <h3 class="text-xs font-semibold text-muted-foreground">{{ t('cases.exchange_product.title') }}</h3>
                    <div class="flex items-center gap-3 rounded-md border border-border p-2" dir="rtl">
                        <img
                            v-if="item.exchange_product.image"
                            :src="item.exchange_product.image"
                            class="size-14 shrink-0 rounded-md border border-border object-cover"
                            alt=""
                        />
                        <span
                            v-else
                            class="flex size-14 shrink-0 items-center justify-center rounded-md bg-muted text-center text-2xs text-muted-foreground"
                            >{{ t('cases.exchange_product.no_image') }}</span
                        >
                        <div class="min-w-0 flex-1 space-y-0.5">
                            <p class="truncate text-sm font-medium">{{ item.exchange_product.title }}</p>
                            <p v-if="item.exchange_product.variant_title" class="truncate text-xs text-muted-foreground">
                                {{ item.exchange_product.variant_title }}
                            </p>
                            <p v-if="item.exchange_product.price !== null" class="text-xs tabular-nums">
                                {{ formatMoney(item.exchange_product.price, locale) }}
                            </p>
                            <a
                                v-if="item.exchange_product.url"
                                :href="item.exchange_product.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex text-xs font-medium text-primary hover:underline"
                                >{{ t('cases.exchange_product.open') }}</a
                            >
                        </div>
                    </div>
                </div>

                <div class="space-y-1.5">
                    <h3 class="text-xs font-semibold text-muted-foreground">{{ t('cases.photos') }}</h3>
                    <p v-if="!item.photos.length" class="text-xs text-muted-foreground">{{ t('cases.no_photos') }}</p>
                    <div v-else class="flex flex-wrap gap-2">
                        <a v-for="photo in item.photos" :key="photo.id" :href="photo.url" target="_blank" rel="noopener">
                            <img :src="photo.url" class="size-16 rounded-md border border-border object-cover" alt="" />
                        </a>
                    </div>
                </div>

                <label class="grid gap-1">
                    <span class="text-xs font-semibold">{{ t('cases.columns.status') }}</span>
                    <select :value="item.status" :disabled="saving" :class="selectClass" @change="onStatusChange">
                        <option v-for="s in ['new', 'in_progress', 'closed']" :key="s" :value="s">{{ t(`cases.tabs.${s}`) }}</option>
                    </select>
                </label>

                <label class="grid gap-1">
                    <span class="text-xs font-semibold">{{ t('cases.assigned') }}</span>
                    <select :value="item.assigned_to?.id ?? ''" :disabled="saving" :class="selectClass" @change="onAssignedChange">
                        <option value="">{{ t('cases.unassigned') }}</option>
                        <option v-for="member in team" :key="member.id" :value="String(member.id)">{{ member.name }}</option>
                    </select>
                </label>

                <p v-if="error" role="alert" class="text-xs text-destructive">{{ error }}</p>

                <Link :href="`/inbox?c=${item.conversation_id}`" class="inline-flex text-xs font-medium text-primary hover:underline">
                    {{ t('cases.open_conversation') }}
                </Link>
            </div>
        </SheetContent>
    </Sheet>
</template>
