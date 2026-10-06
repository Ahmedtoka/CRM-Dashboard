<script setup lang="ts">
/**
 * موافقات الإطلاق (spec 3.5, D3): every launch waiting for the manager, oldest first. Approve = live at once (re-auth 15 min, A6),
 * return to the buyer or reject with a reason, and «وافق على الآمن كله» with a progress bar. Filters live in the URL.
 */
import ApprovalCard from '@/components/ads/launch/ApprovalCard.vue';
import BulkApproveDialog from '@/components/ads/launch/BulkApproveDialog.vue';
import ReasonDialog from '@/components/ads/launch/ReasonDialog.vue';
import ReauthDialog from '@/components/ads/launch/ReauthDialog.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import FilterBar from '@/components/crm/FilterBar.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import type { AdsApprovalsProps, ApproveResult, LaunchRow } from '@/types/ads';
import { Head, router, usePage } from '@inertiajs/vue3';
import { isAxiosError } from 'axios';
import { ShieldCheck } from 'lucide-vue-next';
import { computed, nextTick, onMounted, reactive, ref } from 'vue';

const props = defineProps<AdsApprovalsProps>();

const api = useApi();
const toast = useToast();
const { t } = useI18n();
const me = computed(() => (usePage().props.auth as { user: { id: number } | null }).user?.id ?? null);

const breadcrumbs = computed(() => [
    { title: t('nav.ads'), href: '/ads' },
    { title: t('ads.launch.approvals.title'), href: '/ads/approvals' },
]);

/* ---- filters (URL) ---- */
const f = reactive({
    buyer: props.filters.buyer ?? '',
    account: props.filters.account ?? '',
    age: props.filters.age ?? '',
    fails: props.filters.fails,
    expiring: props.filters.expiring,
});
function apply(): void {
    const q: Record<string, string> = {};
    if (f.buyer) q.buyer = f.buyer;
    if (f.account) q.account = f.account;
    if (f.age) q.age = f.age;
    if (f.fails) q.fails = '1';
    if (f.expiring) q.expiring = '1';
    router.get('/ads/approvals', q, { preserveScroll: true, preserveState: true, replace: true });
}
/* Active filters as FilterBar chips (each removable). */
const chips = computed(() => {
    const out: { key: string; label: string }[] = [];
    const buyer = props.options.buyers.find((b) => String(b.id) === f.buyer);
    const account = props.options.accounts.find((a) => String(a.id) === f.account);
    if (buyer) out.push({ key: 'buyer', label: buyer.name });
    if (account) out.push({ key: 'account', label: account.name });
    if (f.age) out.push({ key: 'age', label: t(`ads.launch.approvals.filters.age_${f.age}`) });
    if (f.fails) out.push({ key: 'fails', label: t('ads.launch.approvals.filters.fails') });
    if (f.expiring) out.push({ key: 'expiring', label: t('ads.launch.approvals.filters.expiring') });
    return out;
});
const moreCount = computed(() => (f.age ? 1 : 0) + (f.fails ? 1 : 0) + (f.expiring ? 1 : 0));
function removeFilter(key: string): void {
    if (key === 'fails' || key === 'expiring') f[key] = false;
    else if (key === 'buyer' || key === 'account' || key === 'age') f[key] = '';
    apply();
}
function clearFilters(): void {
    Object.assign(f, { buyer: '', account: '', age: '', fails: false, expiring: false });
    apply();
}

function reload(): void {
    router.reload({ only: ['launches', 'approvalsLeft', 'writesOn'] });
}

/* ---- re-auth: a 423 parks the action and asks for the password once ---- */
const reauthOpen = ref(false);
let pending: (() => Promise<void>) | null = null;
function needReauth(retry: () => Promise<void>): void {
    pending = retry;
    reauthOpen.value = true;
}
async function reauthed(): Promise<void> {
    const run = pending;
    pending = null;
    if (run) await run();
}

/* ---- approve ---- */
const busyId = ref<string | null>(null);
async function approve(l: LaunchRow, ack: string[]): Promise<void> {
    busyId.value = l.id;
    try {
        const { data } = await api.post<ApproveResult>(`/ads/approvals/${l.id}/approve`, {
            revision: l.revision,
            checks_hash: l.checks_hash,
            ack_warnings: ack,
        });
        const ok = data.ads.filter((a) => a.outcome === 'succeeded').length;
        const failed = data.ads.filter((a) => a.outcome === 'failed').length;
        const message =
            data.launch.state === 'live'
                ? failed
                    ? t('ads.launch.approvals.result.partial', { ok, failed })
                    : t('ads.launch.approvals.result.live')
                : data.launch.state === 'launching'
                  ? t('ads.launch.approvals.result.unknown')
                  : t('ads.launch.approvals.result.failed', { message: data.ads.find((a) => a.message)?.message ?? '' });
        toast.push(message, data.launch.state === 'live' && !failed ? 'success' : 'error');
        reload();
    } catch (e) {
        if (isAxiosError(e) && e.response?.status === 423) {
            needReauth(() => approve(l, ack));
            return;
        }
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
        if (isAxiosError(e) && [409, 422].includes(e.response?.status ?? 0)) reload();
    } finally {
        busyId.value = null;
    }
}

/* ---- return / reject ---- */
const deciding = ref<{ launch: LaunchRow; kind: 'return' | 'reject' } | null>(null);
const decideOpen = computed({ get: () => deciding.value !== null, set: (v) => !v && (deciding.value = null) });
const decideBusy = ref(false);
const decideError = ref<string | null>(null);
async function decide(payload: { code: string; text: string }): Promise<void> {
    if (!deciding.value) return;
    decideBusy.value = true;
    decideError.value = null;
    try {
        const { data } = await api.post<{ message: string }>(`/ads/approvals/${deciding.value.launch.id}/${deciding.value.kind}`, payload);
        toast.push(data.message);
        deciding.value = null;
        reload();
    } catch (e) {
        decideError.value = apiErrorMessage(e, t('common.error'));
    } finally {
        decideBusy.value = false;
    }
}

/* ---- bulk ---- */
const bulkOpen = ref(false);
const bulkResume = ref(0);
/** 423 before the plan: the dialog closed itself, reopen it. 423 mid-run: the dialog is open, resume the pending rows. */
function bulkReauth(): void {
    needReauth(async () => {
        if (bulkOpen.value) bulkResume.value++;
        else bulkOpen.value = true;
    });
}

/* ---- deep link ?launch= ---- */
onMounted(async () => {
    if (!props.filters.launch) return;
    await nextTick();
    document.getElementById(`launch-${props.filters.launch}`)?.scrollIntoView({ block: 'start' });
});

const selfOf = (l: LaunchRow) => props.isAdmin && me.value !== null && (l.people.preparer?.id === me.value || l.people.forwarder?.id === me.value);
const field = 'h-9 rounded-md border border-input bg-background px-2 text-sm';
</script>

<template>
    <Head :title="t('ads.launch.approvals.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-[1100px] space-y-4 p-3 md:p-6">
            <PageHeader :title="t('ads.launch.approvals.title')" :description="t('ads.launch.approvals.description')">
                <span v-if="canApprove" class="text-xs tabular-nums text-muted-foreground">{{
                    t('ads.launch.approvals.left_today', { n: approvalsLeft })
                }}</span>
                <Button v-if="canApprove" size="sm" :disabled="!writesOn || !launches.length" @click="bulkOpen = true">
                    <ShieldCheck aria-hidden="true" />{{ t('ads.launch.approvals.bulk') }}
                </Button>
            </PageHeader>

            <p v-if="!writesOn" role="alert" class="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive">
                {{ t('ads.launch.approvals.writes_off') }}
            </p>
            <p v-if="!canApprove" class="rounded-md bg-muted px-3 py-2 text-xs">{{ t('ads.launch.approvals.read_only') }}</p>

            <FilterBar :chips="chips" :more-count="moreCount" @remove="removeFilter" @clear="clearFilters">
                <template #inline>
                    <label class="sr-only" for="ap-buyer">{{ t('ads.launch.approvals.filters.buyer') }}</label>
                    <select id="ap-buyer" v-model="f.buyer" :class="field" @change="apply">
                        <option value="">{{ t('ads.launch.approvals.filters.all_buyers') }}</option>
                        <option v-for="b in options.buyers" :key="b.id" :value="String(b.id)">{{ b.name }}</option>
                    </select>
                    <label class="sr-only" for="ap-account">{{ t('ads.launch.approvals.filters.account') }}</label>
                    <select id="ap-account" v-model="f.account" :class="field" @change="apply">
                        <option value="">{{ t('ads.launch.approvals.filters.all_accounts') }}</option>
                        <option v-for="a in options.accounts" :key="a.id" :value="String(a.id)">{{ a.name }}</option>
                    </select>
                </template>
                <template #more>
                    <label class="block space-y-1">
                        <span class="text-xs font-medium">{{ t('ads.launch.approvals.filters.age') }}</span>
                        <select v-model="f.age" :class="[field, 'w-full']" @change="apply">
                            <option value="">{{ t('ads.launch.approvals.filters.any_age') }}</option>
                            <option value="1d">{{ t('ads.launch.approvals.filters.age_1d') }}</option>
                            <option value="3d">{{ t('ads.launch.approvals.filters.age_3d') }}</option>
                            <option value="7d">{{ t('ads.launch.approvals.filters.age_7d') }}</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2 text-sm"
                        ><input v-model="f.fails" type="checkbox" class="accent-primary" @change="apply" />{{
                            t('ads.launch.approvals.filters.fails')
                        }}</label
                    >
                    <label class="flex items-center gap-2 text-sm"
                        ><input v-model="f.expiring" type="checkbox" class="accent-primary" @change="apply" />{{
                            t('ads.launch.approvals.filters.expiring')
                        }}</label
                    >
                </template>
            </FilterBar>

            <EmptyState
                v-if="!launches.length"
                :icon="ShieldCheck"
                :title="t('ads.launch.approvals.empty')"
                :body="t('ads.launch.approvals.empty_body')"
            />
            <div v-else class="space-y-4">
                <ApprovalCard
                    v-for="l in launches"
                    :key="l.id"
                    :launch="l"
                    :can-approve="canApprove"
                    :writes-on="writesOn"
                    :busy="busyId === l.id"
                    :is-admin="isAdmin"
                    :self="selfOf(l)"
                    @approve="approve(l, $event)"
                    @return="((deciding = { launch: l, kind: 'return' }), (decideError = null))"
                    @reject="((deciding = { launch: l, kind: 'reject' }), (decideError = null))"
                />
            </div>
        </div>

        <ReasonDialog
            v-model:open="decideOpen"
            :kind="deciding?.kind ?? 'return'"
            :reasons="reasons"
            :busy="decideBusy"
            :error="decideError"
            @submit="decide"
        />
        <ReauthDialog v-model:open="reauthOpen" @confirmed="reauthed" />
        <BulkApproveDialog v-model:open="bulkOpen" :resume="bulkResume" @done="reload" @reauth="bulkReauth" />
    </AppLayout>
</template>
