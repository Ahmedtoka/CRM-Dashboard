<script setup lang="ts">
import type { CardRow } from '@/lib/today';
import { Link } from '@inertiajs/vue3';

/** One «النهارده» card: label · number rows, every number a link to the filtered screen that lists it. */
defineProps<{ title: string; hint?: string; rows: CardRow[]; empty?: string }>();

const tone = (r: CardRow) => (r.tone === 'bad' ? 'text-destructive' : r.tone === 'good' ? 'text-success' : 'text-foreground');
</script>

<template>
    <section class="min-w-0 rounded-lg bg-card p-4 shadow-card">
        <header class="mb-2 flex flex-wrap items-baseline gap-x-2">
            <h2 class="text-sm font-bold text-foreground">{{ title }}</h2>
            <p v-if="hint" class="text-2xs text-muted-foreground">{{ hint }}</p>
        </header>
        <p v-if="rows.length === 0 && empty" class="text-xs text-muted-foreground">{{ empty }}</p>
        <dl v-else class="divide-y divide-border">
            <div v-for="r in rows" :key="r.key" class="flex items-center justify-between gap-3 py-1.5 text-sm">
                <dt class="min-w-0 truncate text-muted-foreground">{{ r.label }}</dt>
                <dd class="shrink-0 text-end font-semibold tabular-nums">
                    <Link
                        v-if="r.href"
                        :href="r.href"
                        class="rounded hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :class="tone(r)"
                        >{{ r.value }}</Link
                    >
                    <span v-else :class="tone(r)">{{ r.value }}</span>
                </dd>
            </div>
        </dl>
        <slot />
    </section>
</template>
