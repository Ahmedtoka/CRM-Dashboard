<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { X } from 'lucide-vue-next';

// The frame of everything that opens beside (or under) the room. It uses the app's own
// colours, so it follows light and dark mode; the room behind it keeps its palette.
// A boolean prop that is not passed is `false` in Vue, so closable must default to true explicitly
// (it did not, and no panel had a close button).
withDefaults(defineProps<{ title: string; subtitle?: string | null; error?: string | null; closable?: boolean }>(), { closable: true });
defineEmits<{ close: [] }>();

const { t } = useI18n();
</script>

<template>
    <section class="rounded-xl border border-border bg-card text-sm leading-normal text-card-foreground shadow-lg" :aria-label="title">
        <!-- Pinned: the close button stays in reach however far the panel scrolls. -->
        <header class="sticky top-0 z-10 flex items-start gap-2 rounded-t-xl border-b border-border bg-card px-4 py-3">
            <div class="min-w-0 flex-1">
                <h2 class="truncate text-base font-bold text-foreground">{{ title }}</h2>
                <p v-if="subtitle" class="truncate text-xs text-muted-foreground">{{ subtitle }}</p>
            </div>
            <button
                v-if="closable"
                type="button"
                class="grid size-9 shrink-0 place-items-center rounded-md border border-border bg-background text-foreground hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
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
