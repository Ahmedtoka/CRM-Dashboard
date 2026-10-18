<script setup lang="ts">
/**
 * One approval card (L 3.1): who / since / version / deadline, the ads and captions (buyer edits marked), where it runs and the
 * parent status (D4), money, landing, checks (warnings ticked «شُفت»), history, and وافق وشغّل / رجّعها للباير / ارفض.
 */
import ChecksPanel from '@/components/ads/launch/ChecksPanel.vue';
import LaunchStateChip from '@/components/ads/launch/LaunchStateChip.vue';
import PlatformChip from '@/components/ads/PlatformChip.vue';
import RelativeTime from '@/components/crm/RelativeTime.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { blockingKeys, launchErrorText, warningKeys } from '@/lib/launch';
import { ADS_TONE } from '@/lib/adsUi';
import type { LaunchRow } from '@/types/ads';
import { Check, ChevronRight } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{ launch: LaunchRow; canApprove: boolean; writesOn: boolean; busy: boolean; isAdmin: boolean; self?: boolean }>(),
    { self: false },
);
const emit = defineEmits<{ approve: [ack: string[]]; return: []; reject: [] }>();

const { t } = useI18n();
const acked = ref<string[]>([]);
watch(
    () => props.launch.checks_hash,
    () => (acked.value = []),
);

const blocking = computed(() => blockingKeys(props.launch.checks));
const warnings = computed(() => warningKeys(props.launch.checks));
const allSeen = computed(() => warnings.value.every((k) => acked.value.includes(k)));
const approvable = computed(
    () =>
        props.launch.can.approve &&
        props.writesOn &&
        props.launch.state === 'awaiting_approval' &&
        blocking.value.length === 0 &&
        allSeen.value &&
        !props.busy,
);
const parentActive = computed(() => {
    const m = props.launch.money;
    return !m || ((m.parent_status ?? 'ACTIVE').toUpperCase() === 'ACTIVE' && (m.campaign_status ?? 'ACTIVE').toUpperCase() === 'ACTIVE');
});
const editedIndex = (i: number) => {
    const o = props.launch.original?.captions[i];
    const c = props.launch.captions[i];
    return !!props.launch.original && (!o || o.headline !== c.headline || o.primary_text !== c.primary_text || o.cta !== c.cta);
};
const status = (s: string | null) =>
    (s ?? '').toUpperCase() === 'ACTIVE' ? t('ads.launch.approvals.card.status_active') : t('ads.launch.approvals.card.status_paused');
</script>

<template>
    <article class="overflow-hidden rounded-lg border border-line bg-card" :aria-labelledby="`launch-${launch.id}`">
        <header class="grid grid-cols-[52px_minmax(0,1fr)] items-start gap-x-3.5 gap-y-2 px-4 pb-3 pt-4 sm:grid-cols-[52px_minmax(0,1fr)_auto]">
            <span class="h-16 w-[52px] overflow-hidden rounded-md bg-sunken">
                <img v-if="launch.material?.thumb_url" :src="launch.material.thumb_url" alt="" class="size-full object-cover" loading="lazy" />
            </span>
            <div class="min-w-0 space-y-1">
                <h2 :id="`launch-${launch.id}`" class="flex min-w-0 items-center gap-2 text-[15px] font-semibold text-foreground">
                    <span class="truncate" dir="auto">{{ launch.material?.title }}</span>
                    <PlatformChip v-if="launch.account" :platform="launch.account.platform" size="xs" icon-only />
                </h2>
                <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[12.5px] text-fg-2">
                    <LaunchStateChip :state="launch.state" />
                    <bdi v-if="launch.account">{{ launch.account.name }}</bdi>
                    <span v-if="launch.people.preparer">{{ t('ads.launch.approvals.card.prepared_by', { name: launch.people.preparer.name }) }}</span>
                    <span v-if="launch.people.buyer">{{ t('ads.launch.approvals.card.reviewed_by', { name: launch.people.buyer.name }) }}</span>
                    <RelativeTime :iso="launch.dates.awaiting_at" class="text-fg-3" />
                </p>
                <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12.5px] text-fg-3">
                    <span>{{ t('ads.launch.approvals.card.revision', { n: launch.revision }) }}</span>
                    <span v-if="launch.money" class="text-fg-2">{{ t('ads.launch.approvals.card.cap', { cap: launch.money.cap, currency: launch.money.currency }) }}</span>
                    <span v-if="launch.dates.expires_at" class="font-medium text-warn">
                        {{ t('ads.launch.approvals.card.expires_in', { time: '' }) }}<RelativeTime :iso="launch.dates.expires_at" mode="datetime" />
                    </span>
                </p>
            </div>
            <span class="col-span-2 justify-self-start rounded-full bg-sunken px-2.5 py-0.5 text-xs font-semibold tabular-nums text-fg-2 sm:col-span-1 sm:justify-self-end">{{
                t('ads.launch.approvals.card.ads_n', { n: launch.ads_count })
            }}</span>
        </header>

        <div class="space-y-3 px-4 pb-4">
            <p v-if="launch.state === 'on_hold'" role="status" class="rounded-md bg-danger-soft px-3 py-2 text-[13px] text-danger">
                {{ t('ads.launch.approvals.card.on_hold') }}
            </p>
            <p v-else-if="launch.state === 'launching'" role="status" class="rounded-md bg-info-soft px-3 py-2 text-[13px] text-info">
                {{ t('ads.launch.approvals.card.launching') }}
            </p>
            <p v-if="launch.last_error && launch.state === 'awaiting_approval'" class="text-xs text-danger">
                {{ t('ads.launch.approvals.card.last_error', { error: launchErrorText(launch.last_error, t) }) }}
            </p>

            <div class="grid gap-4 md:grid-cols-2">
                <section class="min-w-0 space-y-2">
                    <h3 class="text-[13px] font-semibold text-fg-2">{{ t('ads.launch.approvals.card.captions') }}</h3>
                    <ul class="space-y-1.5">
                        <li
                            v-for="(c, i) in launch.captions"
                            :key="i"
                            class="rounded-md border p-2.5 text-[13px]"
                            :class="editedIndex(i) ? 'border-warn/40 bg-warn-soft' : 'border-line'"
                        >
                            <p class="font-semibold text-foreground" dir="auto">{{ c.headline }}</p>
                            <p class="whitespace-pre-line text-fg-2" dir="auto">{{ c.primary_text }}</p>
                            <p class="mt-1 text-xs text-fg-3">
                                {{ t(`ads.launch.editor.cta_labels.${c.cta}`) }}
                                <span v-if="editedIndex(i)" class="ms-2 font-semibold text-warn">{{ t('ads.launch.approvals.card.edited') }}</span>
                            </p>
                        </li>
                    </ul>
                    <div v-if="launch.files.length" class="scrollbar-none flex gap-1.5 overflow-x-auto">
                        <img v-for="f in launch.files" :key="f.id" :src="f.thumb_url ?? ''" alt="" class="h-20 w-16 shrink-0 rounded-md object-cover" loading="lazy" />
                    </div>
                </section>

                <section class="min-w-0 space-y-2 text-[13px]">
                    <h3 class="font-semibold text-fg-2">{{ t('ads.launch.approvals.card.target') }}</h3>
                    <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1.5 rounded-md bg-surface-2 p-2.5">
                        <dt class="text-fg-3">{{ t('ads.launch.approvals.card.account') }}</dt>
                        <dd class="min-w-0 truncate"><bdi>{{ launch.account?.name }}</bdi></dd>
                        <dt class="text-fg-3">{{ t('ads.launch.approvals.card.campaign') }}</dt>
                        <dd class="min-w-0 truncate"><bdi>{{ launch.campaign.name }}</bdi> · {{ status(launch.money?.campaign_status ?? launch.campaign.status) }}</dd>
                        <dt class="text-fg-3">{{ t('ads.launch.approvals.card.adset') }}</dt>
                        <dd class="min-w-0 truncate"><bdi>{{ launch.adset.name }}</bdi> · {{ status(launch.money?.parent_status ?? launch.adset.status) }}</dd>
                    </dl>
                    <h3 class="pt-1 font-semibold text-fg-2">{{ t('ads.launch.approvals.card.money') }}</h3>
                    <p :class="parentActive ? 'font-medium text-warn' : 'text-fg-3'">
                        {{ parentActive ? t('ads.launch.approvals.card.will_spend') : t('ads.launch.approvals.card.will_not_spend') }}
                    </p>
                    <h3 class="pt-1 font-semibold text-fg-2">{{ t('ads.launch.approvals.card.landing') }}</h3>
                    <p dir="ltr" class="break-all text-start text-fg-2">{{ launch.link }}</p>
                    <p v-if="launch.product" class="text-fg-2">
                        {{ t('ads.launch.approvals.card.price', { price: launch.product.prices.join(' / ') }) }} ·
                        {{ t('ads.launch.approvals.card.stock', { n: launch.product.inventory }) }}
                    </p>
                </section>
            </div>

            <ChecksPanel v-model:acked="acked" :checks="launch.checks" :ackable="canApprove" />

            <details v-if="launch.publications?.length || launch.history?.length" class="group text-[13px]">
                <summary class="inline-flex cursor-pointer items-center gap-1 rounded font-medium text-fg-2 hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    <ChevronRight class="rtl-flip size-4 transition-transform duration-150 group-open:rotate-90" aria-hidden="true" />{{
                        t('ads.launch.approvals.card.history')
                    }}
                </summary>
                <ul v-if="launch.publications?.length" class="mt-1.5 space-y-0.5 ps-5">
                    <li v-for="p in launch.publications" :key="p.id" class="flex flex-wrap items-center gap-2">
                        <bdi class="tabular-nums">{{ p.ad_name }}</bdi>
                        <span class="text-fg-3">{{ p.effective_status ?? p.ad_status ?? p.status }}</span>
                        <a v-if="p.manager_url" :href="p.manager_url" target="_blank" rel="noopener" class="text-brand-ink underline-offset-2 hover:underline">{{
                            t('ads.launch.approvals.card.open_manager')
                        }}</a>
                    </li>
                </ul>
                <ol v-if="launch.history?.length" class="ms-5 mt-2 space-y-0.5 border-s border-line ps-3 text-fg-2">
                    <li v-for="(h, i) in launch.history" :key="i">
                        <RelativeTime :iso="h.at" mode="datetime" /> · {{ t(`ads.launch.history.${h.action.replace('launch.', '')}`) }}
                        <template v-if="h.actor"> · {{ h.actor }}</template>
                        <template v-if="h.code"> · {{ t(`ads.launch.reason.${h.code}`) }}</template>
                        <template v-if="h.reason">: {{ h.reason }}</template>
                    </li>
                </ol>
            </details>

            <!-- O1: an admin approving a launch they prepared or forwarded (the page computes `self`) -->
            <p v-if="canApprove && self" class="text-xs text-fg-3">{{ t('ads.launch.approvals.card.self_note') }}</p>
        </div>

        <footer v-if="canApprove" class="flex flex-wrap items-center gap-2 border-t border-line bg-surface-2 px-4 py-3">
            <Button class="h-11 md:h-9" :disabled="!approvable" :loading="busy" @click="emit('approve', acked)">
                <Check aria-hidden="true" />{{ t('ads.launch.approvals.card.approve') }}
            </Button>
            <Button variant="outline" class="h-11 md:h-9" :disabled="!launch.can.return || busy" @click="emit('return')">{{
                t('ads.launch.approvals.card.return')
            }}</Button>
            <Button variant="ghost" class="h-11 md:h-9" :class="ADS_TONE.danger" :disabled="!launch.can.reject || busy" @click="emit('reject')">{{
                t('ads.launch.approvals.card.reject')
            }}</Button>
        </footer>
    </article>
</template>
