<script setup lang="ts">
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import { useInitials } from '@/composables/useInitials';
import { formatCount } from '@/lib/format';
import { formatRatio } from '@/lib/today';
import type { TeamRow, TodayMode } from '@/types/today';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { useId } from 'vue';

/**
 * «الفريق دلوقتي» (mockup «النهارده»): one row per agent of the day, her name opening her report, her desk state as a
 * chip (today only), and what she closed / sold / was rated under her name. The room link for whoever may open it.
 */
const props = defineProps<{ rows: TeamRow[]; mode: TodayMode; canSeeBoard: boolean }>();
const { t, locale, dir } = useI18n();
const { getInitials } = useInitials();
const titleId = useId();

type Tone = 'ok' | 'warn' | 'neutral';
const DESK_TONE: Record<string, Tone> = {
    available: 'ok',
    busy: 'ok',
    pending_break: 'warn',
    break: 'warn',
    checking_out: 'warn',
    offline: 'neutral',
};
const state = (r: TeamRow) => (r.desk_status ? t(`today.team.desk.${r.desk_status}`) : r.online ? t('today.team.online') : t('today.team.offline'));
const stateTone = (r: TeamRow): Tone => (r.desk_status ? (DESK_TONE[r.desk_status] ?? 'neutral') : r.online ? 'ok' : 'neutral');
const n = (v: number) => formatCount(v, locale.value);
</script>

<template>
    <section class="min-w-0 rounded-lg border border-line bg-card" :aria-labelledby="titleId" data-today-team>
        <header class="flex items-center gap-2.5 px-4 pb-2.5 pt-3.5">
            <h2 :id="titleId" class="text-base font-bold leading-snug text-foreground">
                {{ props.mode === 'today' ? t('today.team.title_now') : t('today.team.title') }}
            </h2>
            <Link
                v-if="canSeeBoard"
                href="/board"
                class="ms-auto inline-flex items-center gap-0.5 rounded text-[13px] font-semibold text-brand-ink hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                {{ t('today.team.room') }}<component :is="dir === 'rtl' ? ChevronLeft : ChevronRight" class="size-4" aria-hidden="true" />
            </Link>
        </header>
        <p v-if="rows.length === 0" class="px-4 pb-4 text-[13px] text-fg-2">{{ t('today.team.empty') }}</p>
        <ul v-else class="px-4 pb-2">
            <li v-for="r in rows" :key="r.user.id" class="flex items-center gap-2.5 border-b border-line py-2 last:border-b-0">
                <span
                    class="grid size-7 shrink-0 place-items-center rounded-full bg-brand-soft text-[11px] font-semibold text-brand-ink"
                    aria-hidden="true"
                    >{{ getInitials(r.user.name) }}</span
                >
                <span class="min-w-0 flex-1">
                    <Link :href="r.href" class="block truncate text-[13.5px] font-medium text-foreground hover:underline">{{ r.user.name }}</Link>
                    <span class="block truncate text-xs tabular-nums text-fg-3">
                        {{ t('today.team.line', { windows: n(r.windows_closed), orders: n(r.orders) }) }}
                        <template v-if="r.rating.count > 0">
                            ·
                            <span :class="r.rating.low > 0 ? 'font-semibold text-danger' : ''">{{
                                t('today.team.rating_line', { avg: formatRatio(r.rating.avg, locale), n: n(r.rating.count) })
                            }}</span>
                        </template>
                    </span>
                </span>
                <StatusChip v-if="mode === 'today'" :label="state(r)" :tone="stateTone(r)" dot />
            </li>
        </ul>
    </section>
</template>
