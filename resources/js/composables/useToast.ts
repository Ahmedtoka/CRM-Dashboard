import { readonly, ref } from 'vue';

export interface Toast {
    id: number;
    message: string;
    tone: 'success' | 'error' | 'info';
    link?: { href: string; label: string };
}

const toasts = ref<Toast[]>([]);
let seq = 0;

function dismiss(id: number): void {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

function push(message: string, tone: Toast['tone'] = 'success', link?: Toast['link']): void {
    const id = ++seq;
    toasts.value = [...toasts.value.slice(-3), { id, message, tone, link }];
    window.setTimeout(() => dismiss(id), tone === 'error' ? 8000 : 5000);
}

/** App-wide toasts, rendered once by ToastStack in AppLayout. */
export function useToast() {
    return { toasts: readonly(toasts), push, dismiss };
}
