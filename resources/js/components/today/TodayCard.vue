<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { CardRow } from '@/lib/today';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { useId } from 'vue';

/**
 * One «النهارده» panel (DESIGN §3 Panel + KV row): a title, a line of context, an optional «افتحي» link at the end, then
 * label · number rows, every number a link to the filtered screen that lists it. `bars` draws each row's `share` as a
 * horizontal bar (the «ليه مشترتش» reasons, sorted by the server).
 */
withDefaults(
    defineProps<{
        title: string;
        hint?: string;
        rows: CardRow[];
        empty?: string;
        /** «افتحي الكل»: where the whole list lives. */
        href?: string | null;
        linkLabel?: string;
        bars?: Record<string, number>;
    }>(),
    { hint: undefined, empty: undefined, href: null, linkLabel: undefined, bars: undefined },
);

const { dir } = useI18n();
const titleId = useId();
const tone = (r: CardRow) => (r.tone === 'bad' ? 'text-danger' : r.tone === 'good' ? 'text-ok' : 'text-foreground');
const width = (bars: Record<string, number>, key: string) => {
    const max = Math.max(...Object.values(bars), 0.0001);

    return `${Math.max(2, ((bars[key] ?? 0) / max) * 100)}%`;
};
</script>

<template>
    <section class="min-w-0 rounded-lg border border-line bg-card" :aria-labelledby="titleId">
        <header class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5 px-4 pb-2.5 pt-3.5">
            <h2 :id="titleId" class="text-base font-bold leading-snug text-foreground">{{ title }}</h2>
            <p v-if="hint" class="text-[12.5px] text-fg-2">{{ hint }}</p>
            <Link
                v-if="href && linkLabel"
                :href="href"
                class="ms-auto inline-flex items-center gap-0.5 rounded text-[13px] font-semibold text-brand-ink hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                {{ linkLabel }}<component :is="dir === 'rtl' ? ChevronLeft : ChevronRight" class="size-4" aria-hidden="true" />
            </Link>
        </header>
        <div class="px-4 pb-3">
            <p v-if="rows.length === 0 && empty" class="pb-1 text-[13px] text-fg-2">{{ empty }}</p>
            <dl v-else-if="bars">
                <div v-for="r in rows" :key="r.key" class="grid grid-cols-[minmax(84px,132px)_minmax(0,1fr)_auto] items-center gap-2.5 py-[5px] text-[13px]">
                    <dt class="min-w-0 truncate text-foreground">{{ r.label }}</dt>
                    <span class="h-2.5 overflow-hidden rounded-[5px] bg-sunken" aria-hidden="true">
                        <i class="block h-full rounded-[5px] bg-chart-1" :style="{ width: width(bars, r.key) }" />
                    </span>
                    <dd class="text-end font-semibold tabular-nums">
                        <Link v-if="r.href" :href="r.href" class="rounded hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" :class="tone(r)">{{
                            r.value
                        }}</Link>
                        <span v-else :class="tone(r)">{{ r.value }}</span>
                    </dd>
                </div>
            </dl>
            <dl v-else>
                <div v-for="r in rows" :key="r.key" class="flex items-center justify-between gap-3 border-b border-line py-[9px] text-[13.5px] last:border-b-0">
                    <dt class="min-w-0 truncate text-fg-2">{{ r.label }}</dt>
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
        </div>
    </section>
</template>
