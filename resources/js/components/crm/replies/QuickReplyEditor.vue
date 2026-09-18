<script setup lang="ts">
import FormDialog from '@/components/crm/FormDialog.vue';
import PlatformCheckboxes from '@/components/crm/PlatformCheckboxes.vue';
import VariableMenu from '@/components/crm/replies/VariableMenu.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useCrud } from '@/composables/useCrud';
import { useI18n } from '@/composables/useI18n';
import type { QuickReplyRow, QuickReplyVariable } from '@/types/admin';
import type { PlatformValue, QuickReplyCategory } from '@/types/crm';
import { router } from '@inertiajs/vue3';
import { Paperclip, X } from 'lucide-vue-next';
import { reactive, ref, watch } from 'vue';

const props = defineProps<{ row: QuickReplyRow | null; scope: 'shared' | 'personal'; categories: QuickReplyCategory[]; variables: QuickReplyVariable[] }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [] }>();

const { t } = useI18n();
const api = useApi();
const crud = useCrud('/settings/quick-replies', props.scope);

const form = reactive({ shortcut: '', title: '', body: '', category_id: null as number | null, platforms: [] as PlatformValue[] });
const bodyField = ref<HTMLTextAreaElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const preview = ref<{ body: string; missing: string[] } | null>(null);
const attachmentBusy = ref(false);
let previewTimer: number | undefined;

// Reset the form only when the dialog actually opens — a background reload
// (e.g. after an attachment upload) must not clobber an in-progress edit.
watch(open, (isOpen) => {
    if (!isOpen) return;
    Object.assign(form, {
        shortcut: props.row?.shortcut ?? '',
        title: props.row?.title ?? '',
        body: props.row?.body ?? '',
        category_id: props.row?.category_id ?? null,
        platforms: [...(props.row?.platforms ?? [])],
    });
    crud.error.value = null;
    preview.value = null;
});

watch(
    () => form.body,
    (body) => {
        window.clearTimeout(previewTimer);
        if (!body.trim()) {
            preview.value = null;
            return;
        }
        previewTimer = window.setTimeout(() => void runPreview(body), 400);
    },
);

async function runPreview(body: string): Promise<void> {
    try {
        const { data } = await api.post<{ body: string; missing: string[] }>('/settings/quick-replies/preview', { body });
        if (form.body === body) preview.value = data;
    } catch {
        // The live preview is a convenience only — a transient failure just skips this round.
    }
}

function insertVariable(token: string): void {
    const el = bodyField.value;
    const insertion = `{${token}}`;
    if (!el) {
        form.body += insertion;
        return;
    }
    const start = el.selectionStart ?? form.body.length;
    const end = el.selectionEnd ?? form.body.length;
    form.body = `${form.body.slice(0, start)}${insertion}${form.body.slice(end)}`;
    const caret = start + insertion.length;
    requestAnimationFrame(() => {
        el.focus();
        el.setSelectionRange(caret, caret);
    });
}

async function submit(): Promise<void> {
    const payload = {
        scope: props.scope,
        shortcut: form.shortcut.replace(/^\//, ''),
        title: form.title,
        body: form.body,
        category_id: form.category_id,
        platforms: form.platforms.length ? form.platforms : null,
    };
    if (await crud.save(props.row?.id ?? null, payload)) {
        // `crud` is created once, bound to whichever scope was active when
        // this (persistent, reused-for-both-scopes) editor first mounted —
        // its own reload only ever refreshes that one prop. Reload both
        // explicitly so saving the *other* scope's reply still refreshes its
        // table, regardless of which scope `crud` happened to be bound to.
        router.reload({ only: ['shared', 'personal'] });
        open.value = false;
        emit('saved');
    }
}

async function uploadAttachment(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file || !props.row) return;
    attachmentBusy.value = true;
    crud.error.value = null;
    const body = new FormData();
    body.append('file', file);
    try {
        await api.post(`/settings/quick-replies/${props.row.id}/attachments`, body);
        router.reload({ only: ['shared', 'personal'] });
    } catch (e) {
        crud.error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        attachmentBusy.value = false;
    }
}

async function removeAttachment(attachmentId: number): Promise<void> {
    if (!props.row) return;
    try {
        await api.delete(`/settings/quick-replies/${props.row.id}/attachments/${attachmentId}`);
        router.reload({ only: ['shared', 'personal'] });
    } catch (e) {
        crud.error.value = apiErrorMessage(e, t('common.error'));
    }
}

const input = 'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';
</script>

<template>
    <FormDialog
        v-model:open="open"
        :title="row ? t('settings.quick_replies.edit') : scope === 'personal' ? t('settings.quick_replies.add_personal') : t('settings.quick_replies.add')"
        :busy="crud.busy.value"
        :error="crud.error.value"
        wide
        @submit="submit"
    >
        <div class="grid grid-cols-[8rem_1fr] gap-2">
            <label class="grid gap-1">
                <span class="text-xs font-medium">{{ t('settings.quick_replies.shortcut') }}</span>
                <input v-model="form.shortcut" :class="input" dir="ltr" required maxlength="50" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs font-medium">{{ t('settings.quick_replies.title_label') }}</span>
                <input v-model="form.title" :class="input" dir="auto" required maxlength="255" />
            </label>
        </div>

        <label class="grid gap-1">
            <span class="text-xs font-medium">{{ t('replies.category') }}</span>
            <select v-model="form.category_id" :class="input">
                <option :value="null">{{ t('replies.uncategorized') }}</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
        </label>

        <label class="grid gap-1">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium">{{ t('settings.quick_replies.body') }}</span>
                <VariableMenu :variables="variables" @insert="insertVariable" />
            </div>
            <textarea ref="bodyField" v-model="form.body" rows="4" dir="auto" required maxlength="4000" class="rounded-md border border-input bg-background px-3 py-2 text-sm" />
        </label>

        <div v-if="preview" class="rounded-md border bg-muted/40 px-3 py-2 text-xs">
            <p class="mb-1 font-medium text-muted-foreground">{{ t('replies.preview') }}</p>
            <p dir="auto">{{ preview.body }}</p>
            <p v-if="preview.missing.length" role="alert" class="mt-1 text-amber-700">
                {{ t('replies.missing', { list: preview.missing.map((k) => t('replies.variables.' + k)).join('، ') }) }}
            </p>
        </div>

        <PlatformCheckboxes v-model="form.platforms" :legend="t('settings.rules.platforms')" />

        <div class="grid gap-1.5">
            <span class="text-xs font-medium">{{ t('replies.attachments') }}</span>
            <p v-if="!row" class="text-2xs text-muted-foreground">{{ t('replies.save_first') }}</p>
            <template v-else>
                <ul class="flex flex-wrap gap-2">
                    <li v-for="a in row.attachments" :key="a.id" class="flex items-center gap-1.5 rounded-md border px-2 py-1 text-2xs">
                        <img v-if="a.thumb_url" :src="a.thumb_url" class="size-6 rounded object-cover" alt="" />
                        <Paperclip v-else class="size-3.5" aria-hidden="true" />
                        <span class="max-w-24 truncate" dir="auto">{{ a.original_name ?? a.type }}</span>
                        <button
                            type="button"
                            class="text-muted-foreground hover:text-red-700"
                            :aria-label="t('ui.remove_item', { item: a.original_name ?? a.type })"
                            @click="removeAttachment(a.id)"
                        >
                            <X class="size-3" />
                        </button>
                    </li>
                </ul>
                <button
                    type="button"
                    class="inline-flex h-8 w-fit items-center gap-1.5 rounded-md border px-2.5 text-xs disabled:opacity-50"
                    :disabled="attachmentBusy || row.attachments.length >= 5"
                    @click="fileInput?.click()"
                >
                    <Paperclip class="size-3.5" aria-hidden="true" />{{ t('replies.add_attachment') }}
                </button>
                <input ref="fileInput" type="file" accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip" class="hidden" @change="uploadAttachment" />
            </template>
        </div>
    </FormDialog>
</template>
