<script setup lang="ts">
/**
 * «تنبيهات واقتراحات» of «محتاج قرار» (spec 7.3-7.4, U 5.2): severity groups («فرص» folded), shadow ribbon while
 * notifications are off, buyer cap note, review mode J/K, Stop/Run through the S2 server-diff dialog (source=alert).
 * The page tabs (open / snoozed / closed) choose the feed tab on the server; `mode` follows them.
 */
import AlertCard from '@/components/ads/AlertCard.vue';
import WriteActionDialog from '@/components/ads/WriteActionDialog.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { groupBySeverity, type DismissReason, type SnoozeOption } from '@/lib/adsAlerts';
import type { AlertAdRef, AlertCardData, AlertsMeta } from '@/types/ads';
import { Link, router } from '@inertiajs/vue3';
import { CheckCircle2 } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(
    defineProps<{ alerts: AlertCardData[]; meta: AlertsMeta; mode?: 'open' | 'later' | 'closed'; dataAt?: string | null; currency?: string }>(),
    { mode: 'open', dataAt: null, currency: 'EGP' },
);
const emit = defineEmits<{ 'open-ad': [adId: number] }>();

/** What a card action refreshes on the page (the badge counts too). */
const ALERT_RELOAD = ['alerts', 'alertsMeta', 'counts'];

const { t } = useI18n();
const api = useApi();

const groups = computed(() => groupBySeverity(props.alerts));
const showInfo = ref(false);
/** Review order = what is on screen (info cards only while unfolded). */
const ordered = computed(() => groups.value.filter((g) => g.severity !== 'info' || showInfo.value).flatMap((g) => g.cards));
const reviewing = ref(false);
const focus = ref(0);
const acted = ref<Record<string, string>>({});
const error = ref<string | null>(null);
const dialogOpen = ref(false);
const dialog = ref<{ alertId: number; ad: AlertAdRef; to: 'paused' | 'active'; reason: string; cardKey: string } | null>(null);

const emptyText = computed(() => (props.mode === 'later' ? t('ads.alerts.empty_later') : t('ads.alerts.empty_closed')));
const showToggle = computed(() => props.meta.can_toggle && props.meta.can_open_settings);

function reload(): void {
    router.reload({ only: ALERT_RELOAD });
}

async function snooze(ids: number[], until: SnoozeOption): Promise<void> {
    error.value = null;
    try {
        await api.post('/ads/alerts/snooze', { ids, until });
        reload();
    } catch (e) {
        error.value = apiErrorMessage(e, t('ads.alerts.error'));
    }
}

async function dismiss(ids: number[], reason: DismissReason, note: string): Promise<void> {
    error.value = null;
    try {
        await api.post('/ads/alerts/dismiss', { ids, reason, note: note || null });
        reload();
    } catch (e) {
        error.value = apiErrorMessage(e, t('ads.alerts.error'));
    }
}

function openWrite(card: AlertCardData, to: 'paused' | 'active', alertId: number, ad: AlertAdRef, reason: string): void {
    dialog.value = { alertId, ad, to, reason, cardKey: card.key };
    dialogOpen.value = true;
}

function onDone(): void {
    const d = dialog.value;
    if (d) acted.value[d.cardKey] = t(d.to === 'paused' ? 'ads.alerts.acted_stop' : 'ads.alerts.acted_run', { name: d.ad.name });
    reload();
}

function showFocused(): void {
    const c = ordered.value[focus.value];
    if (!c) return;
    const adId = c.ad?.id ?? c.ads[0]?.id;
    if (adId) emit('open-ad', adId);
    void nextTick(() => document.querySelector(`[data-card="${c.key}"]`)?.scrollIntoView?.({ block: 'nearest' }));
    if (c.reasons.some((r) => !r.seen)) void api.post('/ads/alerts/seen', { ids: c.alert_ids }, { silent: true }).catch(() => undefined);
}

function toggleReview(): void {
    if (reviewing.value) {
        reviewing.value = false;
        return;
    }
    reviewing.value = true;
    focus.value = 0;
    showFocused();
}

function onKey(e: KeyboardEvent): void {
    if (!reviewing.value || dialogOpen.value) return;
    const el = e.target;
    if (el instanceof HTMLElement && (['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName) || el.isContentEditable)) return;
    const k = e.key.toLowerCase();
    if (k === 'j' && focus.value < ordered.value.length - 1) {
        focus.value++;
        showFocused();
    } else if (k === 'k' && focus.value > 0) {
        focus.value--;
        showFocused();
    } else if (k === 'escape') {
        reviewing.value = false;
    }
}

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <section class="space-y-3" aria-labelledby="alerts-title" data-test="alerts">
        <div
            v-if="meta.shadow && mode === 'open'"
            class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
            role="status"
            data-test="shadow-ribbon"
        >
            <span>{{ t('ads.alerts.shadow') }}</span>
            <Link v-if="showToggle" href="/ads/setup/rules" class="font-semibold underline" data-test="shadow-toggle">{{
                t('ads.alerts.shadow_toggle')
            }}</Link>
        </div>

        <header v-if="alerts.length" class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="alerts-title" class="text-sm font-semibold">{{ t('ads.alerts.section') }}</h2>
            <Button
                v-if="mode === 'open' && alerts.length > 1"
                size="sm"
                variant="outline"
                data-test="review"
                :aria-pressed="reviewing"
                @click="toggleReview"
            >
                {{ reviewing ? t('ads.alerts.review_done') : t('ads.alerts.review') }}
            </Button>
        </header>
        <h2 v-else id="alerts-title" class="sr-only">{{ t('ads.alerts.section') }}</h2>
        <p v-if="reviewing" class="text-2xs text-muted-foreground" data-test="review-hint">{{ t('ads.alerts.review_hint') }}</p>
        <p v-if="error" class="text-xs text-destructive" role="alert">{{ error }}</p>

        <EmptyState v-if="!alerts.length && mode !== 'open'" :icon="CheckCircle2" :title="emptyText" />
        <div v-for="g in groups" :key="g.severity" class="space-y-2" :data-test="`group-${g.severity}`">
            <h3 class="text-xs font-semibold text-muted-foreground">
                <button
                    v-if="g.severity === 'info'"
                    type="button"
                    class="hover:underline"
                    :aria-expanded="showInfo"
                    data-test="info-toggle"
                    @click="showInfo = !showInfo"
                >
                    {{ t('ads.alerts.severity.info') }} ({{ g.cards.length }})
                </button>
                <template v-else>{{ t(`ads.alerts.severity.${g.severity}`) }} ({{ g.cards.length }})</template>
            </h3>
            <template v-if="g.severity !== 'info' || showInfo">
                <AlertCard
                    v-for="c in g.cards"
                    :key="c.key"
                    :card="c"
                    :mode="mode"
                    :focused="reviewing && ordered[focus]?.key === c.key"
                    :acted-line="acted[c.key] ?? null"
                    @stop="(id, ad, reason) => openWrite(c, 'paused', id, ad, reason)"
                    @run="(id, ad, reason) => openWrite(c, 'active', id, ad, reason)"
                    @snooze="snooze"
                    @dismiss="dismiss"
                    @open-ad="(id) => emit('open-ad', id)"
                />
            </template>
        </div>
        <p v-if="mode === 'open' && meta.hidden_by_cap > 0" class="text-xs text-muted-foreground" data-test="cap-note">
            {{ t('ads.alerts.cap_note', { n: meta.hidden_by_cap }) }}
        </p>

        <WriteActionDialog
            v-model:open="dialogOpen"
            :account-id="dialog?.ad.account_id ?? 0"
            :account="dialog?.ad.account ?? ''"
            platform=""
            level="ad"
            :external-id="dialog?.ad.external_id ?? ''"
            :name="dialog?.ad.name ?? ''"
            :to="dialog?.to ?? 'paused'"
            :reason="dialog?.reason ?? ''"
            :data-at="dataAt"
            :currency="currency"
            source="alert"
            :source-ref="dialog ? String(dialog.alertId) : null"
            @done="onDone"
        />
    </section>
</template>
