<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { X } from 'lucide-vue-next';

// The frame of everything that opens beside (or under) the room. It uses the app's own
// colours, so it follows light and dark mode; the room behind it keeps its palette.
defineProps<{ title: string; subtitle?: string | null; error?: string | null; closable?: boolean }>();
defineEmits<{ close: [] }>();

const { t } = useI18n();
</script>

<template>
    <section class="rounded-xl border border-border bg-card text-sm leading-normal text-card-foreground shadow-lg" :aria-label="title">
        <header class="flex items-start gap-2 border-b border-border px-4 py-3">
            <div class="min-w-0 flex-1">
                <h2 class="truncate text-base font-bold text-foreground">{{ title }}</h2>
                <p v-if="subtitle" class="truncate text-xs text-muted-foreground">{{ subtitle }}</p>
            </div>
            <button
                v-if="closable !== false"
                type="button"
                class="-me-1 grid size-9 shrink-0 place-items-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :aria-label="t('common.close')"
                @click="$emit('close')"
            >
                <X class="size-4" aria-hidden="true" />
            </button>
        </header>
        <p v-if="error" class="mx-4 mt-3 rounded-md bg-destructive/10 px-3 py-2 text-xs font-semibold text-destructive" role="alert">{{ error }}</p>
        <div class="space-y-4 p-4">
            <slot />
        </div>
    </section>
</template>
