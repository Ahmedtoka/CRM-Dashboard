<script setup lang="ts">
import StatusChip from '@/components/crm/StatusChip.vue';
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
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-card p-4 shadow-card">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-1.5">
                    <h3 class="truncate text-sm font-semibold text-foreground" dir="auto">{{ entry.title }}</h3>
                    <StatusChip v-if="entry.is_template" tone="warning" :label="t('settings.bot_knowledge.template')" />
                </div>
                <p class="text-2xs text-muted-foreground" dir="ltr">{{ entry.key }}</p>
            </div>
            <label class="inline-flex shrink-0 cursor-pointer items-center gap-1.5">
                <span class="relative inline-flex h-5 w-9 items-center">
                    <input
                        type="checkbox"
                        class="peer sr-only"
                        :checked="localActive"
                        :aria-label="t('settings.bot_knowledge.active')"
                        @change="toggleActive(($event.target as HTMLInputElement).checked)"
                    />
                    <span class="pointer-events-none absolute inset-0 rounded-full bg-muted transition-colors peer-checked:bg-primary" aria-hidden="true" />
                    <span
                        class="pointer-events-none absolute start-0.5 size-4 rounded-full bg-background shadow transition-transform peer-checked:translate-x-4 rtl:peer-checked:-translate-x-4"
                        aria-hidden="true"
                    />
                </span>
                <span class="text-2xs text-muted-foreground">{{ t('settings.bot_knowledge.active') }}</span>
            </label>
        </div>

        <div v-if="editing" class="space-y-2">
            <input v-model="draft.title" dir="auto" maxlength="255" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm" />
            <textarea v-model="draft.body" dir="auto" rows="4" maxlength="5000" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm" />
            <p v-if="error" role="alert" class="text-xs text-destructive">{{ error }}</p>
            <div class="flex gap-2">
                <button type="button" class="h-8 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground disabled:opacity-50" :disabled="busy" @click="save">
                    {{ t('common.save') }}
                </button>
                <button type="button" class="h-8 rounded-md border border-input px-3 text-xs" @click="cancel">{{ t('common.cancel') }}</button>
            </div>
        </div>
        <template v-else>
            <p class="line-clamp-4 text-sm text-foreground" dir="auto">{{ entry.body }}</p>
            <div class="flex items-center gap-2">
                <button type="button" class="inline-flex h-7 items-center gap-1 rounded-md px-2 text-xs text-muted-foreground hover:bg-muted hover:text-foreground" @click="edit">
                    <Pencil class="size-3.5" aria-hidden="true" />{{ t('ui.edit') }}
                </button>
                <button
                    v-if="deletable"
                    type="button"
                    class="inline-flex h-7 items-center gap-1 rounded-md px-2 text-xs text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                    @click="remove"
                >
                    <Trash2 class="size-3.5" aria-hidden="true" />{{ t('ui.delete') }}
                </button>
                <span v-else class="text-2xs text-muted-foreground">{{ t('settings.bot_knowledge.core_locked') }}</span>
            </div>
        </template>
    </div>
</template>
