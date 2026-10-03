<script setup lang="ts">
import { SidebarProvider } from '@/components/ui/sidebar';
import { onMounted, ref } from 'vue';

interface Props {
    variant?: 'header' | 'sidebar';
    /** Inbox and board: the sidebar starts collapsed to its icon rail (its own remembered state). */
    workspace?: boolean;
}

const props = withDefaults(defineProps<Props>(), { variant: 'sidebar', workspace: false });

const storageKey = props.workspace ? 'sidebar:workspace' : 'sidebar';
const isOpen = ref(!props.workspace);

onMounted(() => {
    try {
        const stored = localStorage.getItem(storageKey);
        isOpen.value = props.workspace ? stored === 'true' : stored !== 'false';
    } catch {
        // Storage blocked: keep the default.
    }
});

const handleSidebarChange = (open: boolean) => {
    isOpen.value = open;
    try {
        localStorage.setItem(storageKey, String(open));
    } catch {
        // Storage blocked: the state just won't persist.
    }
};
</script>

<template>
    <div v-if="variant === 'header'" class="flex min-h-screen w-full flex-col">
        <slot />
    </div>
    <SidebarProvider v-else :default-open="isOpen" :open="isOpen" @update:open="handleSidebarChange">
        <slot />
    </SidebarProvider>
</template>
