<script setup lang="ts">
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { router } from '@inertiajs/vue3';
import { LoaderCircle, Sparkles } from 'lucide-vue-next';
import { ref, type Component } from 'vue';

/**
 * Empty state for a settings list that can be seeded with a small ready-made set
 * (POST `endpoint`, idempotent server-side), then reloads `reloadProp`. Without
 * `endpoint` (e.g. the viewer may not add shared items) only the text shows.
 */
const props = defineProps<{
    icon: Component;
    title: string;
    body: string;
    /** What the one-click action adds, shown before it is clicked. */
    preview?: string[];
    endpoint?: string;
    reloadProp: string;
}>();

const { t } = useI18n();
const api = useApi();
const toast = useToast();
const busy = ref(false);

async function addExamples(): Promise<void> {
    if (!props.endpoint || busy.value) return;
    busy.value = true;
    try {
        const { data } = await api.post<{ data: { created: number } }>(props.endpoint);
        const created = data.data.created;
        toast.push(created > 0 ? t('settings.starter.added', { n: created }) : t('settings.starter.none_added'));
        router.reload({ only: [props.reloadProp] });
    } catch (e) {
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="flex flex-col items-center gap-3 rounded-lg bg-card px-4 py-10 text-center shadow-card">
        <div class="flex size-12 items-center justify-center rounded-full bg-surface-accent text-primary">
            <component :is="icon" class="size-5" aria-hidden="true" />
        </div>
        <div class="space-y-1">
            <h2 class="text-base font-semibold text-foreground">{{ title }}</h2>
            <p class="mx-auto max-w-sm text-xs text-muted-foreground">{{ body }}</p>
        </div>

        <template v-if="endpoint">
            <div v-if="preview?.length" class="flex max-w-md flex-wrap items-center justify-center gap-1.5">
                <span class="text-2xs text-muted-foreground">{{ t('settings.starter.will_add') }}</span>
                <span v-for="item in preview" :key="item" class="rounded-full bg-elevated px-2 py-0.5 text-2xs text-foreground" dir="auto">{{
                    item
                }}</span>
            </div>
            <button
                type="button"
                class="inline-flex h-10 items-center gap-1.5 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary-hover disabled:opacity-60"
                :disabled="busy"
                @click="addExamples"
            >
                <LoaderCircle v-if="busy" class="size-4 animate-spin" aria-hidden="true" />
                <Sparkles v-else class="size-4" aria-hidden="true" />{{ t('settings.starter.add') }}
            </button>
        </template>
        <slot />
    </section>
</template>
