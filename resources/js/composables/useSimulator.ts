import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast, type Toast } from '@/composables/useToast';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

export interface SimCustomer {
    key: string;
    name: string;
}

const STORAGE_KEY = 'crm.simulator.customers';

/** Recently simulated customers (per browser), so a follow-up message lands in the same conversation. */
function loadCustomers(): SimCustomer[] {
    try {
        const parsed = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '[]');
        return Array.isArray(parsed) ? parsed.slice(0, 20) : [];
    } catch {
        return [];
    }
}

const customers = ref<SimCustomer[]>(typeof window !== 'undefined' ? loadCustomers() : []);

export function useSimulator() {
    const api = useApi();
    const { t } = useI18n();
    const toast = useToast();
    const busy = ref<string | null>(null);

    function remember(customer: SimCustomer): void {
        customers.value = [customer, ...customers.value.filter((c) => c.key !== customer.key)].slice(0, 20);
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(customers.value));
        } catch {
            // Storage can be unavailable (private mode); the list simply is not kept.
        }
    }

    function newCustomer(name: string): SimCustomer {
        return { key: `sim-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`, name };
    }

    /** POSTs a simulator action; toasts success (text may depend on the response) or the server error. */
    async function post<T = unknown>(
        name: string,
        url: string,
        payload: Record<string, unknown>,
        success: string | ((data: T) => string),
        link?: Toast['link'],
        reload?: string[],
    ): Promise<T | null> {
        busy.value = name;
        try {
            const { data } = await api.post<T>(url, payload);
            toast.push(typeof success === 'function' ? success(data) : success, 'success', link);
            if (reload) router.reload({ only: reload });
            return data;
        } catch (error) {
            toast.push(apiErrorMessage(error, t('common.error')), 'error');
            return null;
        } finally {
            busy.value = null;
        }
    }

    return { customers, busy, remember, newCustomer, post };
}
