<script setup lang="ts">
import AskBotBox from '@/components/crm/bot/AskBotBox.vue';
import KnowledgeEntryCard from '@/components/crm/bot/KnowledgeEntryCard.vue';
import SizeChartEditor from '@/components/crm/bot/SizeChartEditor.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useCrud } from '@/composables/useCrud';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BotKnowledgeEntry, SizeChartData } from '@/types/admin';
import { Head, router } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{
    entries: BotKnowledgeEntry[];
    sizeChart: SizeChartData;
    sizeChartImageUrl: string | null;
    coreKeys: string[];
}>();

const { t } = useI18n();
const api = useApi();
const toast = useToast();
const crud = useCrud('/settings/bot-knowledge/entries', 'entries');

const breadcrumbs = computed(() => [{ title: t('settings.bot_knowledge.title'), href: '/settings/bot-knowledge' }]);

// New-entry dialog (edits happen inline in each KnowledgeEntryCard instead).
const open = ref(false);
const form = reactive({ key: '', title: '', body: '' });

function addEntry(): void {
    Object.assign(form, { key: '', title: '', body: '' });
    crud.error.value = null;
    open.value = true;
}

async function submit(): Promise<void> {
    if (await crud.save(null, { ...form })) open.value = false;
}

const chart = ref<SizeChartData>({ ...props.sizeChart });

const imageInput = ref<HTMLInputElement | null>(null);
const imageBusy = ref(false);
const imageError = ref<string | null>(null);

async function uploadImage(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    imageBusy.value = true;
    imageError.value = null;
    const body = new FormData();
    body.append('file', file);
    try {
        await api.post('/settings/bot-knowledge/size-chart/image', body);
        toast.push(t('settings.bot_knowledge.saved'));
        router.reload({ only: ['sizeChartImageUrl'] });
    } catch (e) {
        imageError.value = apiErrorMessage(e, t('common.error'));
    } finally {
        imageBusy.value = false;
    }
}

async function removeImage(): Promise<void> {
    imageBusy.value = true;
    imageError.value = null;
    try {
        await api.delete('/settings/bot-knowledge/size-chart/image');
        toast.push(t('ui.deleted'));
        router.reload({ only: ['sizeChartImageUrl'] });
    } catch (e) {
        imageError.value = apiErrorMessage(e, t('common.error'));
    } finally {
        imageBusy.value = false;
    }
}
</script>

<template>
    <Head :title="t('settings.bot_knowledge.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('settings.bot_knowledge.title')" :description="t('settings.bot_knowledge.description')">
                <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground" @click="addEntry">
                    <Plus class="size-3.5" aria-hidden="true" />{{ t('settings.bot_knowledge.add_entry') }}
                </button>
            </PageHeader>

            <AskBotBox />

            <div class="grid gap-3 md:grid-cols-2">
                <KnowledgeEntryCard v-for="entry in entries" :key="entry.id" :entry="entry" :deletable="!coreKeys.includes(entry.key)" />
            </div>

            <section class="space-y-3 rounded-lg border border-border bg-card p-4 shadow-card">
                <h2 class="text-sm font-semibold text-foreground">{{ t('settings.bot_knowledge.size_chart') }}</h2>
                <SizeChartEditor v-model="chart" />

                <div class="space-y-2 border-t border-border pt-3">
                    <h3 class="text-xs font-semibold text-muted-foreground">{{ t('settings.bot_knowledge.image') }}</h3>
                    <img v-if="sizeChartImageUrl" :src="sizeChartImageUrl" :alt="t('settings.bot_knowledge.image')" class="max-h-64 rounded-md border border-border object-contain" />
                    <p class="text-2xs text-muted-foreground">{{ t('settings.bot_knowledge.image_hint') }}</p>
                    <p v-if="imageError" role="alert" class="text-xs text-destructive">{{ imageError }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="inline-flex h-8 items-center gap-1.5 rounded-md border border-input px-3 text-xs disabled:opacity-50"
                            :disabled="imageBusy"
                            @click="imageInput?.click()"
                        >
                            {{ t('settings.bot_knowledge.upload_image') }}
                        </button>
                        <button
                            v-if="sizeChartImageUrl"
                            type="button"
                            class="inline-flex h-8 items-center gap-1.5 rounded-md border border-input px-3 text-xs text-destructive disabled:opacity-50"
                            :disabled="imageBusy"
                            @click="removeImage"
                        >
                            <Trash2 class="size-3.5" aria-hidden="true" />{{ t('settings.bot_knowledge.remove_image') }}
                        </button>
                        <input ref="imageInput" type="file" accept="image/jpeg,image/png" class="hidden" @change="uploadImage" />
                    </div>
                </div>
            </section>
        </div>

        <FormDialog v-model:open="open" :title="t('settings.bot_knowledge.add_entry')" :busy="crud.busy.value" :error="crud.error.value" @submit="submit">
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.bot_knowledge.key') }}</span>
                <input v-model="form.key" dir="ltr" required maxlength="60" pattern="^[a-z0-9_]+$" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm" />
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.bot_knowledge.title_label') }}</span>
                <input v-model="form.title" dir="auto" required maxlength="255" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm" />
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.bot_knowledge.body') }}</span>
                <textarea v-model="form.body" dir="auto" required rows="4" maxlength="5000" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm" />
            </label>
        </FormDialog>
    </AppLayout>
</template>
