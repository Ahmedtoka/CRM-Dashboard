<script setup lang="ts">
/**
 * الإطلاقات (spec 3.5): content's own launches, the buyer's «مستنية مراجعتك», live launches with a one-click Stop (D6), and the
 * open-slots switchboard for buyers and Ads authority. Every action is a server call; the row's `can` decides what shows.
 */
import LaunchEditor from '@/components/ads/launch/LaunchEditor.vue';
import LaunchStateChip from '@/components/ads/launch/LaunchStateChip.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import RelativeTime from '@/components/crm/RelativeTime.vue';
import SkeletonList from '@/components/crm/SkeletonList.vue';
import ToggleSwitch from '@/components/crm/ToggleSwitch.vue';
import { Button } from '@/components/ui/button';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { launchErrorText, newIdempotencyKey } from '@/lib/launch';
import type { AdsLaunchesProps, LaunchAnswer, LaunchRow, SlotRow } from '@/types/ads';
import { Head, router } from '@inertiajs/vue3';
import { Rocket } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

const props = defineProps<AdsLaunchesProps>();

const api = useApi();
const toast = useToast();
const { t } = useI18n();

type Tab = AdsLaunchesProps['box'] | 'slots';
const tab = ref<Tab>(props.filters.tab === 'slots' && props.canToggleSlots ? 'slots' : props.box);
const tabs = computed<{ key: Tab; count: number | null }[]>(() => [
    { key: 'mine', count: props.counts.mine },
    ...(props.canReview ? [{ key: 'review' as Tab, count: props.counts.review }] : []),
    { key: 'live', count: props.counts.live },
    ...(props.canToggleSlots ? [{ key: 'slots' as Tab, count: null }] : []),
]);

function go(box: Tab, extra: Record<string, string | number | null> = {}): void {
    tab.value = box;
    if (box === 'slots') {
        void loadSlots();
        return;
    }
    const query: Record<string, string | number> = { box };
    if (props.filters.material !== null) query.material = props.filters.material;
    for (const [k, v] of Object.entries(extra)) if (v !== null) query[k] = v;
    router.get('/ads/launches', query, { preserveScroll: true, replace: true });
}

function page(n: number): void {
    const query: Record<string, string | number> = { box: props.box, page: n };
    if (props.filters.material !== null) query.material = props.filters.material;
    router.get('/ads/launches', query, { preserveScroll: true, preserveState: true });
}

const breadcrumbs = computed(() => [
    { title: t('nav.ads'), href: '/ads' },
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
    if (tab.value === 'slots') void loadSlots();
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
        <div class="mx-auto w-full max-w-[1200px] space-y-4 p-3 md:p-6">
            <PageHeader :title="t('ads.launch.page.title')" :description="t('ads.launch.page.description')" />

            <nav class="flex flex-wrap gap-1 border-b" :aria-label="t('ads.launch.page.title')">
                <button
                    v-for="x in tabs"
                    :key="x.key"
                    type="button"
                    class="-mb-px border-b-2 px-3 py-2 text-sm"
                    :class="
                        tab === x.key ? 'border-primary font-semibold text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'
                    "
                    :aria-current="tab === x.key ? 'page' : undefined"
                    @click="go(x.key)"
                >
                    {{ t(`ads.launch.page.box.${x.key}`) }}
                    <span v-if="x.count" class="ms-1 rounded-full bg-muted px-1.5 text-2xs tabular-nums">{{ x.count }}</span>
                </button>
            </nav>

            <p v-if="filters.material !== null && tab !== 'slots'" class="flex items-center gap-2 text-xs text-muted-foreground">
                {{ t('ads.launch.page.material_filter') }}
                <button type="button" class="text-primary hover:underline" @click="router.get('/ads/launches', { box })">
                    {{ t('ads.launch.page.clear_filter') }}
                </button>
            </p>

            <p
                v-if="filters.stop && tab === 'live'"
                role="alert"
                class="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
            >
                {{ t('ads.launch.page.stop_banner') }}
            </p>

            <!-- open slots -->
            <section v-if="tab === 'slots'" class="space-y-2">
                <p class="text-xs text-muted-foreground">{{ t('ads.launch.page.slots_hint') }}</p>
                <SkeletonList v-if="slots === null" variant="table" :count="6" />
                <EmptyState v-else-if="!slots.length" :title="t('ads.launch.page.slots_empty')" />
                <ul v-else class="divide-y rounded-lg border bg-card">
                    <li v-for="s in slots" :key="s.id" class="flex flex-wrap items-center gap-3 px-3 py-2">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ s.name }}</p>
                            <p class="truncate text-2xs text-muted-foreground">{{ s.account.name }} · {{ s.campaign.name }}</p>
                        </div>
                        <ToggleSwitch
                            :model-value="s.open"
                            :label="s.open ? t('ads.launch.page.slot_open') : t('ads.launch.page.slot_closed')"
                            @update:model-value="toggleSlot(s, $event)"
                        />
                    </li>
                </ul>
            </section>

            <!-- launches -->
            <section v-else class="space-y-2">
                <EmptyState
                    v-if="!launches.data.length"
                    :icon="Rocket"
                    :title="t(`ads.launch.page.empty.${box}`)"
                    :body="t('ads.launch.page.empty_body')"
                />
                <ul v-else class="space-y-2">
                    <li v-for="l in launches.data" :key="l.id" class="flex flex-wrap items-center gap-3 rounded-lg border bg-card p-3">
                        <img
                            v-if="l.material?.thumb_url"
                            :src="l.material.thumb_url"
                            alt=""
                            class="size-14 shrink-0 rounded-md object-cover"
                            loading="lazy"
                        />
                        <div class="min-w-0 flex-1 space-y-0.5">
                            <p class="truncate text-sm font-semibold">{{ l.material?.title }}</p>
                            <p class="truncate text-2xs text-muted-foreground">{{ l.account?.name }} · {{ l.adset.name }}</p>
                            <p class="flex flex-wrap items-center gap-2 text-2xs text-muted-foreground">
                                <LaunchStateChip :state="l.state" />
                                <span>{{ t('ads.launch.approvals.card.ads_n', { n: l.ads_count }) }}</span>
                                <RelativeTime :iso="l.dates.submitted_at ?? l.dates.created_at" />
                                <span v-if="l.decision" class="text-amber-800 dark:text-amber-200">
                                    {{ t(`ads.launch.reason.${l.decision.code}`)
                                    }}<template v-if="l.decision.reason">: {{ l.decision.reason }}</template>
                                </span>
                            </p>
                            <p v-if="l.last_error && l.state === 'create_failed'" class="text-2xs text-destructive">
                                {{ launchErrorText(l.last_error, t) }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <Button v-if="l.can.edit" size="sm" @click="editing = l">{{ t('ads.launch.actions.open') }}</Button>
                            <Button v-if="l.can.retry" size="sm" variant="outline" :loading="busy === l.id" @click="retry(l)">{{
                                t('ads.launch.actions.retry')
                            }}</Button>
                            <Button v-if="l.can.stop && l.state === 'live'" size="sm" variant="destructive" @click="askStop([l])">{{
                                t('ads.launch.actions.stop')
                            }}</Button>
                            <Button
                                v-if="l.can.retire"
                                size="sm"
                                variant="outline"
                                @click="((retiring = l), (retireReason = ''), (retireError = null))"
                                >{{ t('ads.launch.actions.retire') }}</Button
                            >
                        </div>
                    </li>
                </ul>
                <div v-if="launches.last_page > 1" class="flex items-center justify-center gap-2 text-xs">
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="launches.current_page <= 1"
                        :aria-label="t('ui.prev')"
                        @click="page(launches.current_page - 1)"
                        ><span class="inline-block rtl:rotate-180" aria-hidden="true">&lsaquo;</span></Button
                    >
                    <span class="tabular-nums">{{ launches.current_page }} / {{ launches.last_page }}</span>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="launches.current_page >= launches.last_page"
                        :aria-label="t('ui.next')"
                        @click="page(launches.current_page + 1)"
                        ><span class="inline-block rtl:rotate-180" aria-hidden="true">&rsaquo;</span></Button
                    >
                </div>
            </section>
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
                <span class="text-xs font-medium">{{ t('ads.launch.actions.retire_reason') }}</span>
                <input
                    v-model="retireReason"
                    type="text"
                    maxlength="500"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
            </label>
        </FormDialog>
    </AppLayout>
</template>
