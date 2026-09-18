import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import type { Attachment, PendingUpload } from '@/types/crm';
import { MAX_ATTACHMENTS } from '@/types/crm';
import axios from 'axios';
import { computed, onScopeDispose, ref, watch } from 'vue';

export function useAttachmentUploads(conversationId: () => number | null) {
    const api = useApi();
    const { t } = useI18n();
    const items = ref<PendingUpload[]>([]);
    const files = new Map<string, File>();
    const controllers = new Map<string, AbortController>();

    const busy = computed(() => items.value.some((i) => !i.attachment && !i.error));
    const ready = computed(() => items.value.flatMap((i) => (i.attachment ? [i.attachment] : [])));

    function abort(key: string): void {
        controllers.get(key)?.abort();
        controllers.delete(key);
    }

    async function upload(key: string): Promise<void> {
        const id = conversationId();
        const file = files.get(key);
        const item = items.value.find((i) => i.key === key);
        if (id === null || !file || !item) return;
        item.error = null;
        item.progress = 0;
        const form = new FormData();
        form.append('file', file);
        const controller = new AbortController();
        controllers.set(key, controller);
        try {
            const { data } = await api.post<{ data: Attachment }>(`/inbox/conversations/${id}/attachments`, form, {
                signal: controller.signal,
                onUploadProgress: (e) => (item.progress = e.total ? Math.round((e.loaded / e.total) * 100) : 0),
            });
            item.attachment = data.data;
            item.progress = 100;
        } catch (e) {
            if (axios.isCancel(e)) return; // removed/cleared/disposed mid-upload — nothing to show
            item.error = apiErrorMessage(e, t('media.upload_failed'));
        } finally {
            controllers.delete(key);
        }
    }

    function add(list: File[]): void {
        const room = MAX_ATTACHMENTS - items.value.length;
        if (list.length > room) window.alert(t('media.too_many', { n: MAX_ATTACHMENTS }));
        for (const file of list.slice(0, Math.max(0, room))) {
            const key = `up-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
            files.set(key, file);
            items.value.push({
                key,
                name: file.name,
                size: file.size,
                progress: 0,
                attachment: null,
                error: null,
                previewUrl: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
            });
            void upload(key);
        }
    }

    function addExisting(list: Attachment[]): void {
        for (const attachment of list.slice(0, Math.max(0, MAX_ATTACHMENTS - items.value.length))) {
            items.value.push({
                key: `att-${attachment.id}`,
                name: attachment.original_name ?? '',
                size: attachment.size_bytes ?? 0,
                previewUrl: attachment.thumb_url,
                progress: 100,
                attachment,
                error: null,
            });
        }
    }

    function remove(key: string): void {
        const item = items.value.find((i) => i.key === key);
        if (item?.previewUrl?.startsWith('blob:')) URL.revokeObjectURL(item.previewUrl);
        abort(key);
        files.delete(key);
        items.value = items.value.filter((i) => i.key !== key);
    }

    function clear(): void {
        items.value.forEach((i) => {
            if (i.previewUrl?.startsWith('blob:')) URL.revokeObjectURL(i.previewUrl);
            abort(i.key);
        });
        files.clear();
        items.value = [];
    }

    watch(conversationId, clear);

    onScopeDispose(clear);

    return { items, busy, ready, add, addExisting, remove, retry: (key: string) => void upload(key), clear };
}
