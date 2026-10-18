<script setup lang="ts">
import CaseSlaChip from '@/components/crm/cases/CaseSlaChip.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { casePriorityTone, caseStatusTone, caseTypeTone } from '@/lib/caseStatus';
import { formatCount, formatDateTime, formatMoney } from '@/lib/format';
import type { SupportCase } from '@/types/crm';
import { Link } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { MessagesSquare } from 'lucide-vue-next';
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

const phone = useMediaQuery('(max-width: 759px)');
const selectClass = 'h-9 w-full rounded-md border border-line-strong bg-card px-2 text-13 disabled:opacity-60';
</script>

<template>
    <Sheet v-model:open="open">
        <!-- 440px from the end side on desktop; a bottom sheet on a phone (DESIGN §3 Sheet, §6). -->
        <SheetContent
            :side="phone ? 'bottom' : dir === 'rtl' ? 'left' : 'right'"
            class="flex w-full flex-col gap-0 p-0"
            :class="phone ? 'max-h-[90dvh] rounded-t-xl' : 'sm:max-w-[440px]'"
        >
            <SheetHeader class="space-y-1.5 border-b border-line px-5 pb-3.5 pt-4 text-start">
                <SheetTitle class="flex flex-wrap items-center gap-2 text-lg font-bold">
                    {{ item ? t('cases.card_title', { id: item.id }) : t('cases.title') }}
                    <StatusChip v-if="item" :label="t(`cases.types.${item.type}`)" :tone="caseTypeTone[item.type] ?? 'neutral'" />
                </SheetTitle>
                <!-- The header line repeats the chips under it; screen readers still get it as the sheet's description. -->
                <SheetDescription v-if="item" class="sr-only" :dir="dir">{{ item.summary_header }}</SheetDescription>
                <div v-if="item" class="flex flex-wrap items-center gap-1.5 pt-0.5" data-case-chips>
                    <StatusChip :label="t(`cases.tabs.${item.status}`)" :tone="caseStatusTone[item.status]" />
                    <CaseSlaChip :sla="item.sla" />
                    <StatusChip :label="t(`cases.priority.${item.priority}`)" :tone="casePriorityTone[item.priority]" size="sm" />
                    <StatusChip v-if="item.request_kind" :label="t(`cases.types.${item.request_kind}`)" tone="info" size="sm" />
                </div>
            </SheetHeader>

            <div v-if="loading" class="space-y-3 p-5" aria-busy="true" :aria-label="t('ui.loading')">
                <Skeleton class="h-5 w-1/2" />
                <Skeleton class="h-16 w-full" />
                <Skeleton class="h-16 w-full" />
                <Skeleton class="h-24 w-full" />
            </div>

            <!-- What failed, and a way to try again (DESIGN §4: no bare «حصل خطأ»). -->
            <EmptyState v-else-if="error && !item" variant="error" :title="t('cases.load_error')" :body="error">
                <template #action>
                    <Button v-if="caseId !== null" variant="outline" size="sm" @click="load(caseId)">{{ t('ui.retry') }}</Button>
                </template>
            </EmptyState>

            <div v-else-if="item" class="scrollbar-thin flex-1 space-y-5 overflow-y-auto px-5 py-4 text-sm">
                <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-13" data-case-meta>
                    <template v-if="item.customer">
                        <dt class="text-fg-2">{{ t('cases.columns.customer') }}</dt>
                        <dd class="min-w-0">
                            <Link
                                v-if="item.customer.id"
                                :href="`/customers/${item.customer.id}`"
                                class="font-semibold text-brand-ink hover:underline"
                                dir="auto"
                                >{{ item.customer.name ?? '—' }}</Link
                            >
                            <bdi v-if="item.customer.phone" dir="ltr" class="ms-2 tabular-nums text-fg-2">{{ item.customer.phone }}</bdi>
                        </dd>
                    </template>
                    <dt class="text-fg-2">{{ t('cases.columns.order') }}</dt>
                    <dd>
                        <Link v-if="item.order_id" :href="`/orders/${item.order_id}`" class="font-semibold text-brand-ink hover:underline"
                            ><bdi dir="ltr">{{ item.order_number ?? `#${item.order_id}` }}</bdi></Link
                        >
                        <span v-else class="text-fg-3">{{ t('cases.no_order') }}</span>
                    </dd>
                    <template v-if="item.request_kind && item.reason">
                        <dt class="text-fg-2">{{ t('cases.reason') }}</dt>
                        <dd dir="auto">{{ item.reason }}</dd>
                    </template>
                    <dt class="text-fg-2">{{ t('cases.created_at') }}</dt>
                    <dd class="tabular-nums">{{ formatDateTime(item.created_at, locale) }}</dd>
                </dl>
                <p v-if="item.opened_by" class="-mt-3 text-xs text-fg-2">{{ t('cases.sla.opened_by', { name: item.opened_by.name }) }}</p>

                <CaseSummarySections :sections="item.summary_sections" />

                <div v-if="item.items?.length" class="space-y-2">
                    <h3 class="text-13 font-bold">{{ t('cases.items.title') }}</h3>
                    <div class="overflow-x-auto rounded-lg border border-line">
                        <table class="w-full text-13" :dir="dir">
                            <thead class="bg-surface-2 text-xs font-semibold text-fg-2">
                                <tr>
                                    <th scope="col" class="px-3 py-2 text-start">{{ t('cases.items.item') }}</th>
                                    <th scope="col" class="px-3 py-2 text-start">{{ t('cases.items.variant') }}</th>
                                    <th scope="col" class="px-3 py-2 text-center">{{ t('cases.items.qty') }}</th>
                                    <th scope="col" class="px-3 py-2 text-end">{{ t('cases.items.price') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, index) in item.items" :key="index" class="border-t border-line">
                                    <td class="px-3 py-2" dir="auto">
                                        <span class="font-semibold">{{ row.title }}</span>
                                        <StatusChip
                                            v-if="row.exchange_only"
                                            class="ms-1.5"
                                            :label="t('cases.items.exchange_only')"
                                            tone="warn"
                                            size="sm"
                                        />
                                    </td>
                                    <td class="px-3 py-2 text-fg-2" dir="auto">{{ row.variant ?? '—' }}</td>
                                    <td class="px-3 py-2 text-center tabular-nums">{{ formatCount(row.qty, locale) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums">
                                        {{ row.price !== null ? formatMoney(row.price, locale) : '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="item.exchange_product" class="space-y-2">
                    <h3 class="text-13 font-bold">{{ t('cases.exchange_product.title') }}</h3>
                    <div class="flex items-center gap-3 rounded-lg border border-line p-2.5" :dir="dir">
                        <img
                            v-if="item.exchange_product.image"
                            :src="item.exchange_product.image"
                            class="h-[70px] w-14 shrink-0 rounded-md object-cover"
                            alt=""
                        />
                        <span
                            v-else
                            class="flex h-[70px] w-14 shrink-0 items-center justify-center rounded-md bg-sunken text-center text-2xs text-fg-2"
                            >{{ t('cases.exchange_product.no_image') }}</span
                        >
                        <div class="min-w-0 flex-1 space-y-0.5">
                            <p class="truncate font-semibold" dir="auto">{{ item.exchange_product.title }}</p>
                            <p v-if="item.exchange_product.variant_title" class="truncate text-xs text-fg-2" dir="auto">
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
                                class="inline-flex text-xs font-semibold text-brand-ink hover:underline"
                                >{{ t('cases.exchange_product.open') }}</a
                            >
                        </div>
                    </div>
                </div>

                <div class="space-y-2">
                    <h3 class="text-13 font-bold">{{ t('cases.photos') }}</h3>
                    <p v-if="!item.photos.length" class="text-13 text-fg-3">{{ t('cases.no_photos') }}</p>
                    <div v-else class="flex flex-wrap gap-2">
                        <a
                            v-for="photo in item.photos"
                            :key="photo.id"
                            :href="photo.url"
                            target="_blank"
                            rel="noopener"
                            class="rounded-md focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <img :src="photo.url" class="h-20 w-16 rounded-md border border-line object-cover" alt="" />
                        </a>
                    </div>
                </div>
            </div>

            <!-- The footer: the two controls that move a case, then the conversation as the one primary action. -->
            <SheetFooter
                v-if="item"
                class="flex-col gap-3 border-t border-line bg-card px-5 py-3.5 pb-[max(0.875rem,env(safe-area-inset-bottom))] sm:flex-col"
            >
                <div class="grid grid-cols-2 gap-3">
                    <label class="grid min-w-0 gap-1">
                        <span class="text-xs font-semibold text-fg-2">{{ t('cases.columns.status') }}</span>
                        <select :value="item.status" :disabled="saving" :class="selectClass" data-case-status @change="onStatusChange">
                            <option v-for="s in ['new', 'in_progress', 'resolved', 'closed']" :key="s" :value="s">{{ t(`cases.tabs.${s}`) }}</option>
                        </select>
                    </label>
                    <label class="grid min-w-0 gap-1">
                        <span class="text-xs font-semibold text-fg-2">{{ t('cases.assigned') }}</span>
                        <select
                            :value="item.assigned_to?.id ?? ''"
                            :disabled="saving"
                            :class="selectClass"
                            data-case-assigned
                            @change="onAssignedChange"
                        >
                            <option value="">{{ t('cases.unassigned') }}</option>
                            <option v-for="member in team" :key="member.id" :value="String(member.id)">{{ member.name }}</option>
                        </select>
                    </label>
                </div>
                <p v-if="error" role="alert" class="text-xs text-danger">{{ error }}</p>
                <Button as-child size="lg" class="h-11 w-full">
                    <Link :href="`/inbox?c=${item.conversation_id}`"> <MessagesSquare aria-hidden="true" />{{ t('cases.open_conversation') }} </Link>
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
