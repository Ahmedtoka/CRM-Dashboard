import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * JSON CRUD against a settings resource (`POST base`, `PUT base/{id}`, `DELETE base/{id}`),
 * then a partial Inertia reload of the page prop that lists it.
 */
export function useCrud(base: string, reloadProp: string) {
    const api = useApi();
    const { t } = useI18n();
    const toast = useToast();

    const busy = ref(false);
    const error = ref<string | null>(null);

    function reload(): void {
        router.reload({ only: [reloadProp] });
    }

    async function save(id: number | null, payload: Record<string, unknown>, successKey = 'ui.saved'): Promise<boolean> {
        busy.value = true;
        error.value = null;
        try {
            if (id === null) await api.post(base, payload);
            else await api.put(`${base}/${id}`, payload);
            toast.push(t(successKey));
            reload();
            return true;
        } catch (e) {
            error.value = apiErrorMessage(e, t('common.error'));
            return false;
        } finally {
            busy.value = false;
        }
    }

    async function remove(id: number, confirmText: string, successKey = 'ui.deleted'): Promise<boolean> {
        if (!window.confirm(confirmText)) return false;
        try {
            await api.delete(`${base}/${id}`);
            toast.push(t(successKey));
            reload();
            return true;
        } catch (e) {
            toast.push(apiErrorMessage(e, t('common.error')), 'error');
            return false;
        }
    }

    return { busy, error, save, remove, reload };
}
