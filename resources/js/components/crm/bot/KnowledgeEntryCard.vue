<script setup lang="ts">
import IconAction from '@/components/crm/IconAction.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import ToggleSwitch from '@/components/crm/ToggleSwitch.vue';
import { Button } from '@/components/ui/button';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import type { BotKnowledgeEntry } from '@/types/admin';
import { router } from '@inertiajs/vue3';
import { Pencil, Trash2 } from 'lucide-vue-next';
import { reactive, ref, watch } from 'vue';

const props = defineProps<{ entry: BotKnowledgeEntry; deletable: boolean }>();
const emit = defineEmits<{ save: [payload: { title: string; body: string }]; toggle: [active: boolean]; remove: [] }>();

const { t } = useI18n();
const api = useApi();
const toast = useToast();

const editing = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);
const draft = reactive({ title: props.entry.title, body: props.entry.body });

// Optimistic local mirror of is_active so the switch can be reverted immediately
// on a failed request, without waiting on a full Inertia reload of `entries`.
const localActive = ref(props.entry.is_active);
watch(
    () => props.entry.is_active,
    (v) => {
        localActive.value = v;
    },
);

function edit(): void {
    draft.title = props.entry.title;
    draft.body = props.entry.body;
    error.value = null;
    editing.value = true;
}

function cancel(): void {
    editing.value = false;
    error.value = null;
}

async function save(): Promise<void> {
    // Only the fields the user actually changed — an untouched save must
    // neither hit the network nor (via the server's is_template-clearing
    // logic) silently drop the "نموذج — عدّله" badge on an unedited template.
    const payload: Partial<{ title: string; body: string }> = {};
    if (draft.title !== props.entry.title) payload.title = draft.title;
    if (draft.body !== props.entry.body) payload.body = draft.body;
    if (Object.keys(payload).length === 0) {
        editing.value = false;
        return;
    }

    busy.value = true;
    error.value = null;
    try {
        await api.put(`/settings/bot-knowledge/entries/${props.entry.id}`, payload);
        emit('save', { title: draft.title, body: draft.body });
        toast.push(t('settings.bot_knowledge.saved'));
        editing.value = false;
        router.reload({ only: ['entries'] });
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        busy.value = false;
    }
}

async function toggleActive(next: boolean): Promise<void> {
    const previous = localActive.value;
    localActive.value = next;
    try {
        await api.put(`/settings/bot-knowledge/entries/${props.entry.id}`, { is_active: next });
        emit('toggle', next);
        router.reload({ only: ['entries'] });
    } catch (e) {
        localActive.value = previous;
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    }
}

async function remove(): Promise<void> {
    if (!window.confirm(t('ui.confirm_delete', { name: props.entry.title }))) return;
    try {
        await api.delete(`/settings/bot-knowledge/entries/${props.entry.id}`);
        emit('remove');
        toast.push(t('ui.deleted'));
        router.reload({ only: ['entries'] });
    } catch (e) {
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    }
}
</script>

<template>
    <section class="flex min-w-0 flex-col rounded-lg border border-line bg-card" :class="{ 'opacity-70': !localActive }">
        <div class="flex items-center gap-2 px-4 pb-2 pt-3.5">
            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
                <h3 class="truncate text-base font-bold text-foreground" dir="auto" :title="entry.key">{{ entry.title }}</h3>
                <StatusChip v-if="entry.is_template" tone="warn" size="sm" :label="t('settings.bot_knowledge.template')" />
            </div>
            <div class="flex shrink-0 items-center gap-0.5">
                <ToggleSwitch
                    :model-value="localActive"
                    :label="t('settings.bot_knowledge.active')"
                    :disabled="busy"
                    @update:model-value="toggleActive"
                />
                <template v-if="!editing">
                    <IconAction :icon="Pencil" :label="t('ui.edit')" @click="edit" />
                    <IconAction v-if="deletable" :icon="Trash2" variant="destructive" :label="t('ui.delete')" @click="remove" />
                </template>
            </div>
        </div>

        <div v-if="editing" class="space-y-2 px-4 pb-4">
            <input
                v-model="draft.title"
                dir="auto"
                maxlength="255"
                :aria-label="t('settings.bot_knowledge.title_label')"
                class="h-9 w-full rounded-md border border-line-strong bg-card px-3 text-sm focus-visible:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40"
            />
            <textarea
                v-model="draft.body"
                dir="auto"
                rows="4"
                maxlength="5000"
                :aria-label="t('settings.bot_knowledge.body')"
                class="w-full rounded-md border border-line-strong bg-card px-3 py-2 text-sm focus-visible:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40"
            />
            <p v-if="error" role="alert" class="text-13 text-danger">{{ error }}</p>
            <div class="flex gap-2">
                <Button type="button" size="sm" :loading="busy" @click="save">{{ t('common.save') }}</Button>
                <Button type="button" size="sm" variant="outline" @click="cancel">{{ t('common.cancel') }}</Button>
            </div>
        </div>
        <div v-else class="space-y-2 px-4 pb-4">
            <p class="line-clamp-4 text-[13.5px] leading-[1.75] text-fg-2" dir="auto">{{ entry.body }}</p>
            <p v-if="!deletable" class="text-xs text-fg-3">{{ t('settings.bot_knowledge.core_locked') }}</p>
        </div>
    </section>
</template>
