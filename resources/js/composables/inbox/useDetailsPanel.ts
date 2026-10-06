import { computed, ref, type Ref } from 'vue';

/**
 * The inbox details panel (C 2.1, G16): her column (>= 1280, remembered), the sheet below xl, and
 * an overlay that opens by itself when a queue window is delivered while the column is closed
 * (the usual 1366/1440 laptop). The overlay never changes her remembered choice.
 */
export function useDetailsPanel(opts: { isXl: Ref<boolean>; initialOpen: boolean }) {
    const open = ref(opts.initialOpen);
    const overlay = ref(false);
    const sheet = ref(false);

    function toggle(): void {
        if (overlay.value) {
            overlay.value = false;

            return;
        }
        if (opts.isXl.value) open.value = !open.value;
        else sheet.value = !sheet.value;
    }

    function onWindowDelivered(): void {
        if (opts.isXl.value && !open.value) overlay.value = true;
    }

    function onSelect(): void {
        overlay.value = false;
        sheet.value = false;
    }

    const showColumn = computed(() => opts.isXl.value && open.value);
    const active = computed(() => (opts.isXl.value ? open.value || overlay.value : sheet.value));

    return { open, overlay, sheet, showColumn, active, toggle, onWindowDelivered, onSelect };
}
