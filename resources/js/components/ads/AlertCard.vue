<script setup lang="ts">
/**
 * One decision of «محتاج قرار» (spec 7.3, U 5.2): an ad with its reasons stacked, an out-of-stock product with its ads,
 * or an account finding. One primary verb in a fixed place, «بعدين» (tomorrow 09:00 / 3 d / 7 d) and «مش موافق» chips.
 * `mode` follows the page tab: later shows the snooze date, closed shows how it closed and no actions.
 */
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { alertSentence, DISMISS_REASONS, SNOOZE_OPTIONS, type DismissReason, type SnoozeOption } from '@/lib/adsAlerts';
import { formatDateTime } from '@/lib/format';
import type { AlertAdRef, AlertCardData, AlertReason } from '@/types/ads';
import { router } from '@inertiajs/vue3';
import { ADS_TONE } from '@/lib/adsUi';
import { CheckCircle2, ChevronDown, Megaphone, PackageX } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{ card: AlertCardData; focused?: boolean; actedLine?: string | null; mode?: 'open' | 'later' | 'closed'; busy?: boolean }>(),
    {
        focused: false,
        busy: false,
        actedLine: null,
        mode: 'open',
    },
);
const emit = defineEmits<{
    stop: [alertId: number, ad: AlertAdRef, reason: string];
    run: [alertId: number, ad: AlertAdRef, reason: string];
    snooze: [ids: number[], until: SnoozeOption];
    dismiss: [ids: number[], reason: DismissReason, note: string];
    'open-ad': [adId: number];
}>();

const { t, locale } = useI18n();
const laterOpen = ref(false);
const disagreeOpen = ref(false);
const chosen = ref<DismissReason | null>(null);
const note = ref('');

const sentences = computed(() => props.card.reasons.map((r) => ({ id: r.alert_id, text: alertSentence(r, locale.value), state: stateLine(r) })));
const reasonText = computed(() => sentences.value.map((s) => s.text).join(' '));
const canDismiss = computed(() => props.card.reasons.every((r) => r.can_dismiss));
const unseen = computed(() => props.mode === 'open' && props.card.reasons.some((r) => !r.seen));
const money = computed(() => Math.round(props.card.money_at_risk_per_day));
const title = computed(() => props.card.ad?.name ?? props.card.product?.title ?? props.card.account?.name ?? '');
const subtitle = computed(() => {
    const ad = props.card.ad;
    if (ad) return [ad.account, ad.buyer].filter(Boolean).join(' · ');
    if (props.card.kind === 'product') return t('ads.alerts.product_ads', { n: props.card.ads.length });
    return props.card.account?.name ?? '';
});
const tone = computed(
    () =>
        ({
            critical: 'text-danger',
            high: 'text-danger',
            medium: 'text-warn',
            info: 'text-info',
        })[props.card.severity],
);
const writeVerb = computed(() => props.card.primary.verb === 'stop' || props.card.primary.verb === 'run');
/** A product card of Stops lists one Stop per ad instead of a single primary. */
const perAdStops = computed(() => props.card.kind === 'product' && props.card.primary.verb === 'stop');
/** open_settings without a link (below supervisor, the page would 403): the sentence plus «اطلب من المدير…». */
const askManager = computed(() => props.card.primary.verb === 'open_settings' && !props.card.primary.href);
const showPrimary = computed(() => {
    const p = props.card.primary;
    if (props.mode === 'closed' || perAdStops.value || askManager.value || p.verb === 'why') return false;
    if (writeVerb.value) return props.card.ad !== null;
    return p.href !== null;
});
const primaryDisabled = computed(() => writeVerb.value && !props.card.ad?.can_write);

function stateLine(r: AlertReason): string | null {
    if (props.mode === 'later' && r.snoozed_until) return t('ads.alerts.states.snoozed', { at: formatDateTime(r.snoozed_until, locale.value) });
    if (props.mode !== 'closed' || r.state === 'open' || r.state === 'snoozed') return null;
    const parts = [t(`ads.alerts.states.${r.state}`)];
    if (r.state === 'dismissed' && r.dismiss_reason) parts.push(t(`ads.alerts.disagree.reasons.${r.dismiss_reason}`));
    if (r.closed_by) parts.push(t('ads.alerts.closed_by', { name: r.closed_by }));
    if (r.closed_at) parts.push(formatDateTime(r.closed_at, locale.value));

    return parts.join(' · ');
}

function primary(): void {
    const p = props.card.primary;
    const ad = props.card.ad;
    if (writeVerb.value && ad) {
        if (p.verb === 'stop') emit('stop', p.alert_id, ad, reasonText.value);
        else emit('run', p.alert_id, ad, reasonText.value);
        return;
    }
    if (p.href) router.visit(p.href);
}

function later(option: SnoozeOption): void {
    if (props.busy) return; // a snooze or dismiss of this card is on the way: never post twice
    laterOpen.value = false;
    emit('snooze', props.card.alert_ids, option);
}

function sendDisagree(): void {
    if (!chosen.value || props.busy) return;
    emit('dismiss', props.card.alert_ids, chosen.value, note.value.trim());
    disagreeOpen.value = false;
    chosen.value = null;
    note.value = '';
}
</script>

<template>
    <article
        class="border-b border-line px-4 py-3.5 last:border-b-0 focus:outline-none"
        :class="focused ? 'bg-brand-soft/60 outline outline-2 -outline-offset-2 outline-ring' : ''"
        :data-card="card.key"
        :aria-current="focused ? 'true' : undefined"
        :tabindex="focused ? -1 : undefined"
    >
        <p v-if="actedLine" class="flex items-center gap-2 text-[13px] text-fg-2" role="status" data-test="acted"><CheckCircle2 class="size-4 text-ok" aria-hidden="true" />{{ actedLine }}</p>
        <template v-else>
            <header class="flex items-start gap-3.5">
                <span class="h-16 w-[52px] shrink-0 overflow-hidden rounded-md bg-sunken">
                    <img v-if="card.ad?.thumbnail_url" :src="card.ad.thumbnail_url" alt="" loading="lazy" class="size-full object-cover" />
                    <component :is="card.kind === 'product' ? PackageX : Megaphone" v-else class="m-auto mt-5 size-5 text-fg-3" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-2 text-xs">
                        <span :class="['inline-flex items-center gap-1 font-semibold', tone]">
                            <span class="size-2 rounded-full bg-current" aria-hidden="true" />{{ t(`ads.alerts.severity.${card.severity}`) }}
                        </span>
                        <span v-if="unseen" class="rounded-full bg-brand-soft px-1.5 py-px text-[11px] font-semibold text-brand-ink">{{ t('ads.alerts.new') }}</span>
                    </p>
                    <h3 class="mt-0.5 truncate text-sm font-semibold text-foreground" dir="auto">
                        <bdi>{{ title }}</bdi>
                    </h3>
                    <p class="truncate text-[12.5px] text-fg-2" dir="auto">{{ subtitle }}</p>
                </div>
                <p v-if="money > 0 && mode !== 'closed'" class="shrink-0 rounded-full bg-danger-soft px-2 py-0.5 text-xs font-semibold tabular-nums text-danger" data-test="money">
                    {{ t('ads.alerts.money_at_risk', { money }) }}
                </p>
            </header>

            <ul class="mt-2 space-y-1 ps-[66px] text-[13.5px] leading-relaxed text-foreground max-sm:ps-0">
                <li v-for="s in sentences" :key="s.id">
                    {{ s.text }}
                    <span v-if="s.state" class="block text-xs text-fg-3" data-test="state">{{ s.state }}</span>
                </li>
            </ul>
            <p v-if="askManager" class="mt-1 ps-[66px] text-xs font-medium text-fg-2 max-sm:ps-0" data-test="ask-manager">{{ t('ads.alerts.ask_manager') }}</p>

            <ul v-if="card.kind === 'product'" class="mt-2 divide-y divide-line rounded-md border border-line text-[13px] sm:ms-[66px]">
                <li v-for="a in card.ads" :key="a.id" class="flex items-center justify-between gap-2 px-3 py-1.5">
                    <button type="button" class="min-w-0 truncate text-start hover:underline" dir="auto" @click="emit('open-ad', a.id)">
                        <bdi>{{ a.name }}</bdi> · {{ a.account }}
                    </button>
                    <Button
                        v-if="perAdStops && mode !== 'closed' && a.action === 'stop'"
                        size="sm"
                        variant="ghost"
                        :class="['h-11 md:h-[30px]', ADS_TONE.danger]"
                        :disabled="!a.can_write"
                        :data-test="`stop-${a.id}`"
                        @click="emit('stop', a.alert_id, a, reasonText)"
                    >
                        {{ t('ads.alerts.verbs.stop') }}
                    </Button>
                </li>
            </ul>

            <footer v-if="mode !== 'closed'" class="mt-3 flex flex-wrap items-center gap-1.5 sm:ps-[66px]">
                <Button
                    v-if="showPrimary"
                    size="sm"
                    variant="ghost"
                    :class="['h-11 md:h-[30px]', card.primary.verb === 'stop' ? ADS_TONE.danger : ADS_TONE.ok]"
                    :disabled="primaryDisabled"
                    data-test="primary"
                    @click="primary"
                >
                    {{ t(`ads.alerts.verbs.${card.primary.verb}`) }}
                </Button>
                <Button v-if="card.ad" size="sm" variant="ghost" :class="['h-11 md:h-[30px]', ADS_TONE.quiet]" data-test="why" @click="emit('open-ad', card.ad.id)">{{
                    t('ads.alerts.verbs.why')
                }}</Button>
                <div v-if="mode === 'open'" class="relative">
                    <Button
                        size="sm"
                        variant="ghost"
                        :class="['h-11 md:h-[30px]', ADS_TONE.quiet]"
                        data-test="later"
                        :disabled="busy"
                        :aria-expanded="laterOpen"
                        aria-haspopup="menu"
                        @click="laterOpen = !laterOpen"
                    >
                        {{ t('ads.alerts.later.label') }} <ChevronDown aria-hidden="true" />
                    </Button>
                    <div
                        v-if="laterOpen"
                        role="menu"
                        class="absolute start-0 z-10 mt-1 min-w-40 rounded-lg border border-line bg-popover p-1 shadow-e2"
                    >
                        <button
                            v-for="o in SNOOZE_OPTIONS"
                            :key="o"
                            type="button"
                            role="menuitem"
                            :data-test="`later-${o}`"
                            class="block w-full rounded-md px-2.5 py-2 text-start text-[13px] hover:bg-sunken focus-visible:bg-sunken focus-visible:outline-none"
                            @click="later(o)"
                        >
                            {{ t(`ads.alerts.later.${o}`) }}
                        </button>
                    </div>
                </div>
                <Button
                    v-if="canDismiss"
                    size="sm"
                    variant="ghost"
                    :class="['h-11 md:h-[30px]', ADS_TONE.quiet]"
                    data-test="disagree"
                    :aria-expanded="disagreeOpen"
                    @click="disagreeOpen = !disagreeOpen"
                >
                    {{ t('ads.alerts.disagree.label') }}
                </Button>
            </footer>

            <div v-if="disagreeOpen && mode !== 'closed'" class="mt-2 space-y-2 rounded-md bg-sunken p-3 sm:ms-[66px]">
                <div class="flex flex-wrap gap-1" role="group" :aria-label="t('ads.alerts.disagree.label')">
                    <button
                        v-for="r in DISMISS_REASONS"
                        :key="r"
                        type="button"
                        :data-test="`reason-${r}`"
                        :aria-pressed="chosen === r"
                        :class="['h-8 rounded-full border px-3 text-xs font-medium', chosen === r ? 'border-brand bg-brand-soft text-brand-ink' : 'border-line-strong bg-card text-fg-2 hover:text-foreground']"
                        @click="chosen = r"
                    >
                        {{ t(`ads.alerts.disagree.reasons.${r}`) }}
                    </button>
                </div>
                <textarea
                    v-model="note"
                    maxlength="500"
                    rows="2"
                    class="w-full rounded-md border border-line-strong bg-card p-2 text-[13px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :aria-label="t('ads.alerts.disagree.note')"
                    :placeholder="t('ads.alerts.disagree.note')"
                />
                <Button size="sm" data-test="disagree-send" :disabled="!chosen || busy" :loading="busy" @click="sendDisagree">{{
                    t('ads.alerts.disagree.send')
                }}</Button>
            </div>
        </template>
    </article>
</template>
