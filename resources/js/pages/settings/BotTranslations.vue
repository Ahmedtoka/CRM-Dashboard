<script setup lang="ts">
/**
 * Settings → «الترجمات» (design 2026-09-21 §2). Every Arabic text the bot can say with
 * its English next to it: search, filter to the ones that have no English yet, edit one
 * inline (an edit is kept as «مكتوبة بإيد» and is never overwritten), or ask for it to be
 * translated again. Numbers, links and emoji show as ⟦0⟧ markers — they are never
 * translated, they are put back exactly as they were.
 */
import PageHeader from '@/components/crm/PageHeader.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BotTranslationRow, BotTranslationUsage } from '@/types/admin';
import { Head, router } from '@inertiajs/vue3';
import { Check, LoaderCircle, RotateCw, Search, X } from 'lucide-vue-next';
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
const iconBtn = 'rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50';
</script>

<template>
    <Head :title="t('settings.bot_translations.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('settings.bot_translations.title')" :description="t('settings.bot_translations.description')" />

            <div class="flex flex-wrap items-center gap-2">
                <label class="relative flex-1 min-w-52">
                    <Search class="pointer-events-none absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                    <input
                        v-model="query"
                        type="search"
                        dir="auto"
                        :placeholder="t('settings.bot_translations.search')"
                        :aria-label="t('settings.bot_translations.search')"
                        class="h-9 w-full rounded-md border border-input bg-background px-3 ps-8 text-sm"
                    />
                </label>

                <button
                    type="button"
                    :class="[chip, onlyMissing ? 'border-primary bg-primary/10 text-primary' : 'border-input text-muted-foreground']"
                    :aria-pressed="onlyMissing"
                    @click="onlyMissing = !onlyMissing"
                >
                    {{ t('settings.bot_translations.only_missing') }} ({{ missingCount }})
                </button>

                <span class="text-xs text-muted-foreground">{{ t('settings.bot_translations.usage', { used: usage.used, cap: usage.cap }) }}</span>
            </div>

            <p v-if="!engine" class="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-300">
                {{ t('settings.bot_translations.no_engine') }}
            </p>

            <div class="overflow-hidden rounded-md border border-border">
                <table class="w-full text-sm">
                    <caption class="sr-only">{{ t('settings.bot_translations.title') }}</caption>
                    <thead class="bg-muted/50 text-xs text-muted-foreground">
                        <tr>
                            <th scope="col" class="p-2 text-start font-medium">{{ t('settings.bot_translations.arabic') }}</th>
                            <th scope="col" class="p-2 text-start font-medium">{{ t('settings.bot_translations.english') }}</th>
                            <th scope="col" class="p-2 text-start font-medium">{{ t('settings.bot_translations.where') }}</th>
                            <th scope="col" class="p-2 text-end font-medium">{{ t('ui.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!filtered.length">
                            <td colspan="4" class="p-6 text-center text-xs text-muted-foreground">{{ t('settings.bot_translations.empty') }}</td>
                        </tr>
                        <tr v-for="row in filtered" :key="row.source" class="border-t border-border align-top">
                            <td class="max-w-xs p-2" dir="rtl">
                                <span class="whitespace-pre-wrap">{{ row.source }}</span>
                                <span v-if="row.short" class="ms-1 text-[10px] text-muted-foreground">({{ t('settings.bot_translations.button') }})</span>
                            </td>

                            <td class="max-w-xs p-2" dir="ltr">
                                <div v-if="editing === row.source" class="flex items-start gap-1">
                                    <textarea
                                        v-model="draft"
                                        rows="3"
                                        dir="ltr"
                                        class="w-full rounded-md border border-input bg-background p-2 text-sm"
                                        :aria-label="t('settings.bot_translations.english')"
                                    />
                                    <button type="button" :class="iconBtn" :disabled="busy === row.source" :title="t('common.save')" @click="save(row)">
                                        <LoaderCircle v-if="busy === row.source" class="size-3.5 animate-spin" />
                                        <Check v-else class="size-3.5" />
                                    </button>
                                    <button type="button" :class="iconBtn" :title="t('common.cancel')" @click="cancelEdit"><X class="size-3.5" /></button>
                                </div>

                                <button
                                    v-else
                                    type="button"
                                    class="w-full whitespace-pre-wrap text-start hover:underline disabled:cursor-not-allowed disabled:no-underline"
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
                            </td>

                            <td class="p-2 text-xs text-muted-foreground">
                                <span dir="ltr">{{ row.context }}</span>
                                <span v-if="row.orphan" class="ms-1 rounded bg-muted px-1 py-0.5 text-[10px]">{{ t('settings.bot_translations.orphan') }}</span>
                            </td>

                            <td class="p-2 text-end">
                                <button
                                    type="button"
                                    :class="iconBtn"
                                    :disabled="busy === row.source"
                                    :title="t('settings.bot_translations.retranslate')"
                                    :aria-label="t('settings.bot_translations.retranslate')"
                                    @click="retranslate(row)"
                                >
                                    <LoaderCircle v-if="busy === row.source" class="size-3.5 animate-spin" />
                                    <RotateCw v-else class="size-3.5" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
