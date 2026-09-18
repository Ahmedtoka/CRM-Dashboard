<script setup lang="ts">
import { useSidebar } from '@/components/ui/sidebar';
import { onBeforeUnmount, onMounted, watch } from 'vue';

/**
 * Collapses the app sidebar to its icon rail while the page that renders this is open, and puts
 * the owner's own choice back when it closes. It must sit inside AppLayout (the sidebar provider
 * lives there). AppShell keeps the sidebar state in localStorage, so the choice from before is
 * also parked in sessionStorage: a reload of this page still restores it on the way out.
 */
const STORAGE_KEY = 'sidebar';
const PARKED_KEY = 'bot-flows:sidebar-before';

const sidebar = useSidebar();

function read(storage: Storage, key: string): string | null {
    try {
        return storage.getItem(key);
    } catch {
        return null;
    }
}

function write(storage: Storage, key: string, value: string | null): void {
    try {
        if (value === null) storage.removeItem(key);
        else storage.setItem(key, value);
    } catch {
        // Storage can be unavailable; the sidebar then simply stays as it is.
    }
}

const parked = read(window.sessionStorage, PARKED_KEY);
const before = parked ?? (read(window.localStorage, STORAGE_KEY) === 'false' ? 'false' : 'true');
/** Once the owner toggles the sidebar on this page, that choice wins and nothing is restored. */
let ownerChose = false;

function restore(): void {
    window.removeEventListener('pagehide', restore);
    write(window.sessionStorage, PARKED_KEY, null);
    if (ownerChose) return;
    // Writes both AppShell's state and its storage, so the next page opens as before.
    if (before === 'true') sidebar.setOpen(true);
    write(window.localStorage, STORAGE_KEY, before);
}

onMounted(() => {
    write(window.sessionStorage, PARKED_KEY, before);
    window.addEventListener('pagehide', restore);
    sidebar.setOpen(false);
    // Only an expand can come from the owner: this page itself only ever collapses.
    watch(sidebar.open, (open) => {
        if (open) ownerChose = true;
    });
});

onBeforeUnmount(restore);
</script>

<template>
    <span hidden />
</template>
