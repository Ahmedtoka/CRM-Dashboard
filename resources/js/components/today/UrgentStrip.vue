<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { urgentText } from '@/lib/today';
import type { UrgentItem } from '@/types/today';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ items: UrgentItem[] }>();
const { t, locale, dir } = useI18n();
const shown = computed(() => props.items.map((i) => ({ ...i, ...urgentText(i, t, locale.value) })));
</script>

<template>
    <section class="rounded-lg bg-card p-3 shadow-card" aria-labelledby="today-urgent">
        <h2 id="today-urgent" class="mb-2 text-sm font-bold text-foreground">{{ t('today.urgent.title') }}</h2>
        <p v-if="shown.length === 0" class="text-sm text-muted-foreground">{{ t('today.urgent.empty') }}</p>
        <ul v-else class="flex flex-wrap gap-2">
            <li v-for="i in shown" :key="i.key" class="min-w-0 max-w-full">
                <Link
                    :href="i.href"
                    class="inline-flex max-w-full items-center gap-2 rounded-md border px-3 py-2 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :class="
                        i.tone === 'danger'
                            ? 'border-destructive/40 bg-destructive/10 text-destructive'
                            : 'border-warning/40 bg-warning/10 text-foreground'
                    "
                >
                    <span class="min-w-0 [overflow-wrap:anywhere]">{{ i.label }}</span>
                    <span v-if="i.detail" class="text-xs font-normal text-muted-foreground">· {{ i.detail }}</span>
                    <component :is="dir === 'rtl' ? ChevronLeft : ChevronRight" class="size-4 shrink-0" aria-hidden="true" />
                </Link>
            </li>
        </ul>
    </section>
</template>
