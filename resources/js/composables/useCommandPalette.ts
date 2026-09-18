import { ref } from 'vue';

// Module-level singleton: one palette instance, opened from the top-bar
// button or the `mod+k` shortcut registered in AppLayout.vue.
const open = ref(false);

export function useCommandPalette() {
    return {
        open,
        show: () => (open.value = true),
        hide: () => (open.value = false),
    };
}
