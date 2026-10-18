<script setup lang="ts">
/**
 * الإطلاقات (spec 3.5): content's own launches, the buyer's «مستنية مراجعتك», live launches with a one-click Stop (D6), and the
 * open-slots switchboard for buyers and Ads authority. Every action is a server call; the row's `can` decides what shows.
 */
import LaunchEditor from '@/components/ads/launch/LaunchEditor.vue';
import LaunchStateChip from '@/components/ads/launch/LaunchStateChip.vue';
import PlatformChip from '@/components/ads/PlatformChip.vue';
import AdsPanel from '@/components/ads/ui/AdsPanel.vue';
import DecisionRow from '@/components/ads/ui/DecisionRow.vue';
import StageBoxes, { type StageBox } from '@/components/ads/ui/StageBoxes.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import RelativeTime from '@/components/crm/RelativeTime.vue';
import SkeletonList from '@/components/crm/SkeletonList.vue';
import ToggleSwitch from '@/components/crm/ToggleSwitch.vue';
import { Button } from '@/components/ui/button';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { formatCount } from '@/lib/format';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { ADS_META, ADS_TONE } from '@/lib/adsUi';
import { launchErrorText, newIdempotencyKey } from '@/lib/launch';
import type { AdsLaunchesProps, LaunchAnswer, LaunchRow, SlotRow } from '@/types/ads';
import { Head, router } from '@inertiajs/vue3';
import { ChevronRight, MessageSquareText, PackageX, Pause, Plus, Rocket } from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref } from 'vue';

const props = defineProps<AdsLaunchesProps>();

const api = useApi();
const toast = useToast();
const { t, locale } = useI18n();

/** The stage boxes (DESIGN §3 StageBoxes); the open slots are a panel of their own beside the list, not a stage. */
type Tab = AdsLaunchesProps['box'];
const tab = ref<Tab>(props.box);
const boxes = computed<StageBox[]>(() => [
    ...(props.canReview ? [{ key: 'review', label: t('ads.launch.page.box.review'), count: props.counts.review, mine: true }] : []),
    { key: 'mine', label: t('ads.launch.page.box.mine'), count: props.counts.mine, mine: !props.canReview },
    ...(props.canSeeReady ? [{ key: 'ready', label: t('ads.launch.page.box.ready'), count: props.counts.ready }] : []),
    { key: 'live', label: t('ads.launch.page.box.live'), count: props.counts.live },
    ...(props.box === 'all' ? [{ key: 'all', label: t('ads.launch.page.box.all'), count: null }] : []),
]);

function go(box: Tab, extra: Record<string, string | number | null> = {}): void {
    tab.value = box;
    const query: Record<string, string | number> = { box };
    if (props.filters.material !== null) query.material = props.filters.material;
    for (const [k, v] of Object.entries(extra)) if (v !== null) query[k] = v;
    router.get('/ads/launches', query, { preserveScroll: true, replace: true });
}

function page(n: number): void {
    const query: Record<string, string | number> = { box: props.box, page: n };
    if (props.filters.material !== null) query.material = props.filters.material;
    if (props.filters.reviewer) query.reviewer = props.filters.reviewer;
    router.get('/ads/launches', query, { preserveScroll: true, preserveState: true });
}

const breadcrumbs = computed(() => [
    { title: t('nav.ads'), href: '/ads/materials' },
    { title: t('ads.launch.page.title'), href: '/ads/launches' },
]);

/* ---- editor ---- */
const editing = ref<LaunchRow | null>(null);
const editorOpen = computed({ get: () => editing.value !== null, set: (v) => !v && (editing.value = null) });
const editorMode = computed<'content' | 'buyer'>(() => (editing.value?.can.forward || editing.value?.can.send_back ? 'buyer' : 'content'));
function reload(): void {
    router.reload({ only: ['launches', 'counts'] });
}

/* ---- row actions ---- */
const busy = ref<string | null>(null);
async function retry(l: LaunchRow): Promise<void> {
    busy.value = l.id;
    try {
        const { data } = await api.post<LaunchAnswer>(`/ads/launches/${l.id}/retry`);
        toast.push(data.message);
        reload();
    } catch (e) {
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    } finally {
        busy.value = null;
    }
}

const stopping = ref<LaunchRow[]>([]);
const stopOpen = computed({ get: () => stopping.value.length > 0, set: (v) => !v && (stopping.value = []) });
const stopBusy = ref(false);
const stopKey = ref('');
const runningAds = (l: LaunchRow) => (l.publications ?? []).filter((p) => (p.ad_status ?? '').toUpperCase() === 'ACTIVE').length || l.ads_count;
function askStop(list: LaunchRow[]): void {
    stopKey.value = newIdempotencyKey();
    stopping.value = list;
}
async function confirmStop(): Promise<void> {
    stopBusy.value = true;
    try {
        for (const l of stopping.value) {
            const { data } = await api.post<{ message: string }>(
                `/ads/launches/${l.id}/stop`,
                {},
                { headers: { 'Idempotency-Key': `${stopKey.value}-${l.id.slice(-6)}` } },
            );
            toast.push(data.message);
        }
        stopping.value = [];
        reload();
    } catch (e) {
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    } finally {
        stopBusy.value = false;
    }
}

const retiring = ref<LaunchRow | null>(null);
const retireOpen = computed({ get: () => retiring.value !== null, set: (v) => !v && (retiring.value = null) });
const retireReason = ref('');
const retireBusy = ref(false);
const retireError = ref<string | null>(null);
async function confirmRetire(): Promise<void> {
    if (!retiring.value) return;
    retireBusy.value = true;
    retireError.value = null;
    try {
        const { data } = await api.post<LaunchAnswer>(
            `/ads/launches/${retiring.value.id}/retire`,
            { reason: retireReason.value.trim() || null },
            { headers: { 'Idempotency-Key': newIdempotencyKey() } },
        );
        toast.push(data.message);
        retiring.value = null;
        reload();
    } catch (e) {
        retireError.value = apiErrorMessage(e, t('common.error'));
    } finally {
        retireBusy.value = false;
    }
}

/* ---- slots ---- */
const slots = ref<SlotRow[] | null>(null);
const slotsPanel = ref<InstanceType<typeof AdsPanel> | null>(null);
const openCount = computed(() => (slots.value ?? []).filter((s) => s.open).length);
/** One row per ad account: its slots as cells (open = mauve plus, closed = dashed) over the switches. */
const slotGroups = computed(() => {
    const out = new Map<number, { key: number; name: string; platform: string | null; slots: SlotRow[] }>();
    for (const s of slots.value ?? []) {
        const g = out.get(s.account.id) ?? { key: s.account.id, name: s.account.name, platform: s.account.platform ?? null, slots: [] };
        g.slots.push(s);
        out.set(s.account.id, g);
    }
    return [...out.values()];
});
async function loadSlots(): Promise<void> {
    slots.value = null;
    try {
        const { data } = await api.get<{ data: SlotRow[] }>('/ads/slots');
        slots.value = data.data;
    } catch (e) {
        slots.value = [];
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    }
}
async function toggleSlot(s: SlotRow, open: boolean): Promise<void> {
    const before = s.open;
    s.open = open;
    try {
        const { data } = await api.post<{ message: string }>(`/ads/slots/${s.id}`, { open });
        toast.push(data.message);
    } catch (e) {
        s.open = before;
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    }
}

/* ---- deep links ---- */
onMounted(async () => {
    if (props.canToggleSlots) {
        void loadSlots();
        // ?tab=slots (the old slots tab, notifications): bring the panel into view.
        if (props.filters.tab === 'slots')
            void nextTick(() => {
                const el = (slotsPanel.value?.$el ?? null) as HTMLElement | null;
                el?.scrollIntoView?.({ block: 'start' });
                el?.focus?.({ preventScroll: true });
            });
    }
    if (props.filters.launch) {
        const row = props.launches.data.find((l) => l.id === props.filters.launch);
        if (row) editing.value = row.can.edit ? row : null;
        else {
            try {
                const { data } = await api.get<{ launch: LaunchRow }>(`/ads/launches/${props.filters.launch}`);
                if (data.launch.can.edit) editing.value = data.launch;
            } catch {
                /* not visible: the list stays */
            }
        }
    }
    if (props.filters.stop) {
        const live = props.launches.data.filter((l) => l.can.stop && l.state === 'live');
        if (live.length) askStop(live);
    }
});
</script>

<template>
    <Head :title="t('ads.launch.page.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full min-w-0 max-w-[1400px] space-y-5 p-4 md:p-6">
            <PageHeader :title="t('ads.launch.page.title')" :description="t('ads.launch.page.description')" />

            <StageBoxes :boxes="boxes" :current="tab" :label="t('ads.launch.page.title')" @select="(k) => go(k as Tab)" />

            <p v-if="filters.material !== null" class="flex flex-wrap items-center gap-2 text-[13px] text-fg-2">
                <span class="inline-flex h-8 items-center gap-1.5 rounded-full bg-brand-soft px-3 font-medium text-brand-ink">{{ t('ads.launch.page.material_filter') }}</span>
                <button type="button" class="font-medium text-brand-ink underline-offset-2 hover:underline" @click="router.get('/ads/launches', { box })">
                    {{ t('ads.launch.page.clear_filter') }}
                </button>
            </p>

            <p v-if="filters.stop && tab === 'live'" role="alert" class="flex items-center gap-2 rounded-lg bg-danger-soft px-4 py-2.5 text-[13px] font-medium text-danger">
                <PackageX class="size-4 shrink-0" aria-hidden="true" />{{ t('ads.launch.page.stop_banner') }}
            </p>

            <div class="grid items-start gap-5" :class="canToggleSlots ? 'lg:grid-cols-[minmax(0,1fr)_minmax(320px,380px)]' : ''">
                <div class="min-w-0 space-y-3">
                    <!-- «جاهزة للنشر» (F4): per-buyer counts of the drafts waiting for a buyer -->
                    <section v-if="tab === 'ready' && handoff.length" class="space-y-2" data-test="handoff">
                        <p class="text-[13px] text-fg-2">{{ t('ads.launch.page.ready_hint') }}</p>
                        <div class="scrollbar-none -mx-1 flex gap-1.5 overflow-x-auto px-1">
                            <button
                                type="button"
                                class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full border px-3 text-[13px] font-medium"
                                :class="!filters.reviewer ? 'border-foreground bg-foreground text-background' : 'border-line-strong bg-card text-fg-2 hover:text-foreground'"
                                :aria-pressed="!filters.reviewer"
                                @click="go('ready')"
                            >
                                {{ t('ads.launch.page.ready_all') }}
                                <span class="tabular-nums">{{ formatCount(counts.ready, locale) }}</span>
                            </button>
                            <button
                                v-for="b in handoff"
                                :key="b.buyer_id"
                                type="button"
                                class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full border px-3 text-[13px] font-medium"
                                :class="filters.reviewer === b.buyer_id ? 'border-foreground bg-foreground text-background' : 'border-line-strong bg-card text-fg-2 hover:text-foreground'"
                                :aria-pressed="filters.reviewer === b.buyer_id"
                                @click="go('ready', { reviewer: b.buyer_id })"
                            >
                                <bdi>{{ b.buyer_name }}</bdi>
                                <span class="tabular-nums">{{ formatCount(b.count, locale) }}</span>
                                <span v-if="b.oldest_at" class="font-normal opacity-75"> · {{ t('ads.launch.page.ready_oldest') }} <RelativeTime :iso="b.oldest_at" /> </span>
                            </button>
                        </div>
                    </section>

                    <!-- launches of the chosen stage -->
                    <AdsPanel :title="t(`ads.launch.page.box.${tab}`)" :count="launches.total ?? launches.data.length" flush data-test="launches">
                        <EmptyState
                            v-if="!launches.data.length"
                            class="border-0"
                            :icon="Rocket"
                            :title="t(`ads.launch.page.empty.${box}`)"
                            :body="t('ads.launch.page.empty_body')"
                        />
                        <DecisionRow v-for="l in launches.data" v-else :key="l.id">
                            <template #thumb>
                                <img v-if="l.material?.thumb_url" :src="l.material.thumb_url" alt="" class="size-full object-cover" loading="lazy" />
                                <Rocket v-else class="m-auto mt-5 size-5 text-fg-3" aria-hidden="true" />
                            </template>
                            <h3 class="flex min-w-0 items-center gap-2">
                                <span class="truncate text-sm font-semibold" dir="auto">{{ l.material?.title }}</span>
                                <PlatformChip v-if="l.account" :platform="l.account.platform" size="xs" icon-only />
                            </h3>
                            <p :class="ADS_META">
                                <bdi>{{ l.account?.name }}</bdi><template v-if="l.adset.name"> · <bdi>{{ l.adset.name }}</bdi></template>
                                <template v-if="l.people.preparer"> · {{ l.people.preparer.name }}</template>
                            </p>
                            <p class="flex flex-wrap items-center gap-x-2.5 gap-y-1 pt-1 text-xs text-fg-3">
                                <LaunchStateChip :state="l.state" />
                                <span>{{ t('ads.launch.approvals.card.ads_n', { n: l.ads_count }) }}</span>
                                <RelativeTime :iso="l.dates.submitted_at ?? l.dates.created_at" />
                            </p>
                            <p v-if="l.decision" class="mt-1.5 flex items-start gap-2 rounded-md bg-note-soft px-2.5 py-1.5 text-[13px] text-note-ink">
                                <MessageSquareText class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                                <span>
                                    <template v-if="l.people.decider">{{ l.people.decider.name }}: </template>{{ t(`ads.launch.reason.${l.decision.code}`)
                                    }}<template v-if="l.decision.reason"> · {{ l.decision.reason }}</template>
                                </span>
                            </p>
                            <p v-if="l.last_error && l.state === 'create_failed'" class="pt-1 text-xs text-danger">
                                {{ launchErrorText(l.last_error, t) }}
                            </p>
                            <template #actions>
                                <Button v-if="l.can.edit" size="sm" class="h-11 md:h-[30px]" @click="editing = l">{{ t('ads.launch.actions.open') }}</Button>
                                <Button v-if="l.can.retry" size="sm" variant="outline" class="h-11 md:h-[30px]" :loading="busy === l.id" @click="retry(l)">{{
                                    t('ads.launch.actions.retry')
                                }}</Button>
                                <Button
                                    v-if="l.can.stop && l.state === 'live'"
                                    size="sm"
                                    variant="ghost"
                                    class="h-11 md:h-[30px]"
                                    :class="ADS_TONE.danger"
                                    @click="askStop([l])"
                                    ><Pause aria-hidden="true" />{{ t('ads.launch.actions.stop') }}</Button
                                >
                                <Button
                                    v-if="l.can.retire"
                                    size="sm"
                                    variant="ghost"
                                    class="h-11 md:h-[30px]"
                                    :class="ADS_TONE.quiet"
                                    @click="((retiring = l), (retireReason = ''), (retireError = null))"
                                    >{{ t('ads.launch.actions.retire') }}</Button
                                >
                            </template>
                        </DecisionRow>
                        <div v-if="launches.last_page > 1" class="flex items-center justify-between gap-2 border-t border-line px-4 py-2.5 text-[13px] text-fg-2">
                            <span class="tabular-nums">{{ formatCount(launches.current_page, locale) }} / {{ formatCount(launches.last_page, locale) }}</span>
                            <span class="flex gap-1.5">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    class="size-11 p-0 md:size-8"
                                    :disabled="launches.current_page <= 1"
                                    :aria-label="t('ui.prev')"
                                    @click="page(launches.current_page - 1)"
                                    ><ChevronRight class="rtl-flip rotate-180" aria-hidden="true"
                                /></Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    class="size-11 p-0 md:size-8"
                                    :disabled="launches.current_page >= launches.last_page"
                                    :aria-label="t('ui.next')"
                                    @click="page(launches.current_page + 1)"
                                    ><ChevronRight class="rtl-flip" aria-hidden="true"
                                /></Button>
                            </span>
                        </div>
                    </AdsPanel>
                </div>

                <!-- open slots (SlotsBoard): the ad sets content may prepare new materials on -->
                <AdsPanel
                    v-if="canToggleSlots"
                    id="slots"
                    ref="slotsPanel"
                    :title="t('ads.launch.page.box.slots')"
                    :sub="slots ? t('ads.launch.page.slots_open_n', { n: formatCount(openCount, locale), total: formatCount(slots.length, locale) }) : undefined"
                    flush
                    data-test="slots"
                    class="lg:sticky lg:top-[calc(var(--page-header-h,56px)+16px)]"
                    tabindex="-1"
                >
                    <p class="px-4 pb-2 text-[12.5px] text-fg-3">{{ t('ads.launch.page.slots_hint') }}</p>
                    <div v-if="slots === null" class="px-4 pb-4"><SkeletonList variant="table" :count="4" /></div>
                    <EmptyState v-else-if="!slots.length" class="border-0" :title="t('ads.launch.page.slots_empty')" />
                    <div v-else>
                        <div v-for="g in slotGroups" :key="g.key" class="border-t border-line">
                            <div class="flex items-center gap-2 px-4 pb-1.5 pt-3">
                                <PlatformChip :platform="g.platform" size="xs" icon-only />
                                <bdi class="min-w-0 truncate text-[13px] font-semibold">{{ g.name }}</bdi>
                                <span class="ms-auto flex gap-1" aria-hidden="true">
                                    <span
                                        v-for="s in g.slots"
                                        :key="s.id"
                                        class="grid size-[22px] place-items-center rounded-[6px]"
                                        :class="s.open ? 'bg-brand-soft text-brand-ink ring-1 ring-inset ring-brand' : 'border-[1.5px] border-dashed border-line-strong'"
                                    >
                                        <Plus v-if="s.open" class="size-3" />
                                    </span>
                                </span>
                            </div>
                            <ul>
                                <li v-for="s in g.slots" :key="s.id" class="flex items-center gap-3 px-4 py-2">
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-[13.5px] font-medium" dir="auto">{{ s.name }}</p>
                                        <p class="truncate text-xs text-fg-3" dir="auto">{{ s.campaign.name }}</p>
                                    </div>
                                    <ToggleSwitch
                                        :model-value="s.open"
                                        :label="s.open ? t('ads.launch.page.slot_open') : t('ads.launch.page.slot_closed')"
                                        @update:model-value="toggleSlot(s, $event)"
                                    />
                                </li>
                            </ul>
                        </div>
                    </div>
                </AdsPanel>
            </div>
        </div>

        <LaunchEditor
            v-if="editing && editing.material"
            v-model:open="editorOpen"
            :material="editing.material"
            :launch="editing"
            :mode="editorMode"
            :reasons="reasons"
            @saved="reload"
        />

        <FormDialog
            v-model:open="stopOpen"
            :title="t('ads.launch.actions.stop_title', { title: stopping[0]?.material?.title ?? '' })"
            :busy="stopBusy"
            destructive
            :submit-label="t('ads.launch.actions.stop')"
            @submit="confirmStop"
        >
            <p>{{ t('ads.launch.actions.stop_body', { n: stopping.reduce((s, l) => s + runningAds(l), 0) }) }}</p>
        </FormDialog>

        <FormDialog
            v-model:open="retireOpen"
            :title="t('ads.launch.actions.retire_title', { title: retiring?.material?.title ?? '' })"
            :description="t('ads.launch.actions.retire_body')"
            :busy="retireBusy"
            :error="retireError"
            :submit-label="t('ads.launch.actions.retire')"
            @submit="confirmRetire"
        >
            <label class="block space-y-1">
                <span class="text-[13px] font-medium">{{ t('ads.launch.actions.retire_reason') }}</span>
                <input
                    v-model="retireReason"
                    type="text"
                    maxlength="500"
                    class="h-10 w-full rounded-md border border-line-strong bg-card px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
            </label>
        </FormDialog>
    </AppLayout>
</template>
