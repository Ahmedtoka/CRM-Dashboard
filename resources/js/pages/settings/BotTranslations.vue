<script setup lang="ts">
/**
 * Settings → «الترجمات» (design 2026-09-21 §2). Every Arabic text the bot can say with
 * its English next to it: search, filter to the ones that have no English yet, edit one
 * inline (an edit is kept as «مكتوبة بإيد» and is never overwritten), or ask for it to be
 * translated again. Numbers, links and emoji show as ⟦0⟧ markers — they are never
 * translated, they are put back exactly as they were.
 */
import Callout from '@/components/crm/Callout.vue';
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import FilterBar from '@/components/crm/FilterBar.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import IconAction from '@/components/crm/IconAction.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BotTranslationRow, BotTranslationUsage } from '@/types/admin';
import { Head, router } from '@inertiajs/vue3';
import { Check, RotateCw, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ rows: BotTranslationRow[]; usage: BotTranslationUsage; engine: boolean }>();

const { t } = useI18n();
const api = useApi();
const toast = useToast();

const query = ref('');
const onlyMissing = ref(false);
/** The row being edited, by its source text (a missing row has no id yet). */
const editing = ref<string | null>(null);
const draft = ref('');
const busy = ref<string | null>(null);

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();

    return props.rows.filter((row) => {
        if (onlyMissing.value && row.text) return false;
        if (!q) return true;

        return (
            row.source.toLowerCase().includes(q) ||
            (row.text ?? '').toLowerCase().includes(q) ||
            row.context.toLowerCase().includes(q)
        );
    });
});

const missingCount = computed(() => props.rows.filter((row) => !row.text).length);

function startEdit(row: BotTranslationRow): void {
    editing.value = row.source;
    draft.value = row.text ?? '';
}

function cancelEdit(): void {
    editing.value = null;
    draft.value = '';
}

function reload(): void {
    router.reload({ only: ['rows', 'usage'] });
}

async function save(row: BotTranslationRow): Promise<void> {
    const text = draft.value.trim();
    if (!text || row.id === null) return;

    busy.value = row.source;
    try {
        await api.put(`/settings/bot-translations/${row.id}`, { text });
        cancelEdit();
        reload();
        toast.push(t('ui.saved'));
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        busy.value = null;
    }
}

async function retranslate(row: BotTranslationRow): Promise<void> {
    busy.value = row.source;
    try {
        await api.post('/settings/bot-translations/retranslate', { source: row.source, short: row.short });
        reload();
        toast.push(t('settings.bot_translations.retranslated'));
    } catch (error) {
        toast.push(apiErrorMessage(error, t('common.error')), 'error');
    } finally {
        busy.value = null;
    }
}

const breadcrumbs = computed(() => [{ title: t('settings.bot_translations.title'), href: '/settings/bot-translations' }]);
const chip = 'inline-flex h-7 items-center rounded-full border px-2.5 text-xs';

/** DataTable keys rows by `id`; a translation row is one Arabic source string. */
const tableRows = computed(() => filtered.value.map((r) => ({ id: r.source, r })));
const columns = computed<Column[]>(() => [
    { key: 'source', label: t('settings.bot_translations.arabic'), primary: true },
    { key: 'text', label: t('settings.bot_translations.english') },
    { key: 'context', label: t('settings.bot_translations.where') },
    { key: 'actions', label: t('ui.actions'), align: 'end' },
]);

function clearFilters(): void {
    query.value = '';
    onlyMissing.value = false;
}
</script>

<template>
    <Head :title="t('settings.bot_translations.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('settings.bot_translations.title')" :description="t('settings.bot_translations.description')" />

            <div class="rounded-lg bg-card p-3 shadow-card">
                <FilterBar
                    :search="query"
                    :search-placeholder="t('settings.bot_translations.search')"
                    :chips="onlyMissing ? [{ key: 'missing', label: t('settings.bot_translations.only_missing') }] : []"
                    :summary="t('settings.bot_translations.usage', { used: usage.used, cap: usage.cap })"
                    @update:search="query = $event"
                    @remove="onlyMissing = false"
                    @clear="clearFilters"
                >
                    <template #inline>
                        <button
                            type="button"
                            :class="[chip, onlyMissing ? 'border-primary bg-primary/10 text-primary' : 'border-input text-muted-foreground']"
                            :aria-pressed="onlyMissing"
                            @click="onlyMissing = !onlyMissing"
                        >
                            {{ t('settings.bot_translations.only_missing') }} ({{ missingCount }})
                        </button>
                    </template>
                </FilterBar>
            </div>

            <Callout v-if="!engine" tone="warning">{{ t('settings.bot_translations.no_engine') }}</Callout>

            <DataTable
                table-id="bot-translations"
                :columns="columns"
                :rows="tableRows"
                mobile="scroll"
                :empty="t('settings.bot_translations.empty')"
                :caption="t('settings.bot_translations.title')"
            >
                <template #cell-source="{ row: { r: row } }">
                    <span class="max-w-xs whitespace-pre-wrap" dir="auto">{{ row.source }}</span>
                    <span v-if="row.short" class="ms-1 text-[10px] text-muted-foreground">({{ t('settings.bot_translations.button') }})</span>
                </template>
                <template #cell-text="{ row: { r: row } }">
                    <div v-if="editing === row.source" class="flex max-w-xs items-start gap-1">
                        <textarea
                            v-model="draft"
                            rows="3"
                            dir="ltr"
                            class="w-full rounded-md border border-input bg-background p-2 text-sm"
                            :aria-label="t('settings.bot_translations.english')"
                        />
                        <IconAction :icon="Check" size="sm" variant="primary" :label="t('common.save')" :loading="busy === row.source" @click="save(row)" />
                        <IconAction :icon="X" size="sm" :label="t('common.cancel')" @click="cancelEdit" />
                    </div>

                    <button
                        v-else
                        type="button"
                        class="block max-w-xs whitespace-pre-wrap text-start hover:underline disabled:cursor-not-allowed disabled:no-underline"
                        dir="auto"
                        :disabled="row.id === null"
                        :title="row.id === null ? t('settings.bot_translations.missing') : t('ui.edit')"
                        @click="startEdit(row)"
                    >
                        <span v-if="row.text">{{ row.text }}</span>
                        <span v-else class="text-xs text-amber-600 dark:text-amber-400">{{ t('settings.bot_translations.missing') }}</span>
                    </button>

                    <span v-if="row.origin" class="mt-1 block text-[10px] text-muted-foreground">
                        {{ row.origin === 'human' ? t('settings.bot_translations.origin_human') : t('settings.bot_translations.origin_auto') }}
                    </span>
                </template>
                <template #cell-context="{ row: { r: row } }">
                    <span class="text-muted-foreground" dir="ltr">{{ row.context }}</span>
                    <span v-if="row.orphan" class="ms-1 rounded bg-muted px-1 py-0.5 text-[10px]">{{ t('settings.bot_translations.orphan') }}</span>
                </template>
                <template #cell-actions="{ row: { r: row } }">
                    <IconAction :icon="RotateCw" size="sm" :label="t('settings.bot_translations.retranslate')" :loading="busy === row.source" @click="retranslate(row)" />
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
