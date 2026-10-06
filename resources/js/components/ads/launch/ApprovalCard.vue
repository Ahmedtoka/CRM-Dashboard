<script setup lang="ts">
/**
 * One approval card (L 3.1): who / since / version / deadline, the ads and captions (buyer edits marked), where it runs and the
 * parent status (D4), money, landing, checks (warnings ticked «شُفت»), history, and وافق وشغّل / رجّعها للباير / ارفض.
 */
import ChecksPanel from '@/components/ads/launch/ChecksPanel.vue';
import LaunchStateChip from '@/components/ads/launch/LaunchStateChip.vue';
import RelativeTime from '@/components/crm/RelativeTime.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { blockingKeys, warningKeys } from '@/lib/launch';
import type { LaunchRow } from '@/types/ads';
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
    <article class="shadow-xs space-y-3 rounded-xl border bg-card p-4" :aria-labelledby="`launch-${launch.id}`">
        <header class="flex flex-wrap items-start gap-3">
            <img
                v-if="launch.material?.thumb_url"
                :src="launch.material.thumb_url"
                alt=""
                class="size-16 shrink-0 rounded-md object-cover"
                loading="lazy"
            />
            <div class="min-w-0 flex-1 space-y-1">
                <h2 :id="`launch-${launch.id}`" class="truncate text-base font-semibold">{{ launch.material?.title }}</h2>
                <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-2xs text-muted-foreground">
                    <LaunchStateChip :state="launch.state" />
                    <span v-if="launch.people.preparer">{{ t('ads.launch.approvals.card.prepared_by', { name: launch.people.preparer.name }) }}</span>
                    <span v-if="launch.people.buyer">{{ t('ads.launch.approvals.card.reviewed_by', { name: launch.people.buyer.name }) }}</span>
                    <RelativeTime :iso="launch.dates.awaiting_at" />
                    <span>{{ t('ads.launch.approvals.card.revision', { n: launch.revision }) }}</span>
                    <span v-if="launch.dates.expires_at" class="text-amber-800 dark:text-amber-200">
                        {{ t('ads.launch.approvals.card.expires_in', { time: '' }) }}<RelativeTime :iso="launch.dates.expires_at" mode="datetime" />
                    </span>
                </p>
            </div>
            <span class="rounded-full bg-muted px-2 py-0.5 text-xs tabular-nums">{{
                t('ads.launch.approvals.card.ads_n', { n: launch.ads_count })
            }}</span>
        </header>

        <p v-if="launch.state === 'on_hold'" role="status" class="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">
            {{ t('ads.launch.approvals.card.on_hold') }}
        </p>
        <p v-else-if="launch.state === 'launching'" role="status" class="rounded-md bg-info/10 px-3 py-2 text-xs">
            {{ t('ads.launch.approvals.card.launching') }}
        </p>
        <p v-if="launch.last_error && launch.state === 'awaiting_approval'" class="text-2xs text-destructive">
            {{ t('ads.launch.approvals.card.last_error', { error: launch.last_error }) }}
        </p>

        <div class="grid gap-3 md:grid-cols-2">
            <section class="space-y-2">
                <h3 class="text-xs font-semibold">{{ t('ads.launch.approvals.card.captions') }}</h3>
                <ul class="space-y-1.5">
                    <li
                        v-for="(c, i) in launch.captions"
                        :key="i"
                        class="rounded-md border p-2 text-xs"
                        :class="editedIndex(i) ? 'border-warning bg-warning/5' : ''"
                    >
                        <p class="font-semibold">{{ c.headline }}</p>
                        <p class="whitespace-pre-line text-muted-foreground">{{ c.primary_text }}</p>
                        <p class="mt-1 text-2xs">
                            {{ t(`ads.launch.editor.cta_labels.${c.cta}`) }}
                            <span v-if="editedIndex(i)" class="ms-2 font-medium text-amber-800 dark:text-amber-200">{{
                                t('ads.launch.approvals.card.edited')
                            }}</span>
                        </p>
                    </li>
                </ul>
                <div v-if="launch.files.length" class="flex gap-1.5 overflow-x-auto">
                    <img
                        v-for="f in launch.files"
                        :key="f.id"
                        :src="f.thumb_url ?? ''"
                        alt=""
                        class="h-20 w-16 shrink-0 rounded object-cover"
                        loading="lazy"
                    />
                </div>
            </section>

            <section class="space-y-2 text-xs">
                <h3 class="font-semibold">{{ t('ads.launch.approvals.card.target') }}</h3>
                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                    <dt class="text-muted-foreground">{{ t('ads.launch.approvals.card.account') }}</dt>
                    <dd>{{ launch.account?.name }}</dd>
                    <dt class="text-muted-foreground">{{ t('ads.launch.approvals.card.campaign') }}</dt>
                    <dd class="truncate">{{ launch.campaign.name }} · {{ status(launch.money?.campaign_status ?? launch.campaign.status) }}</dd>
                    <dt class="text-muted-foreground">{{ t('ads.launch.approvals.card.adset') }}</dt>
                    <dd class="truncate">{{ launch.adset.name }} · {{ status(launch.money?.parent_status ?? launch.adset.status) }}</dd>
                </dl>
                <h3 class="pt-1 font-semibold">{{ t('ads.launch.approvals.card.money') }}</h3>
                <p v-if="launch.money">{{ t('ads.launch.approvals.card.cap', { cap: launch.money.cap, currency: launch.money.currency }) }}</p>
                <p :class="parentActive ? 'text-amber-900 dark:text-amber-100' : 'text-muted-foreground'">
                    {{ parentActive ? t('ads.launch.approvals.card.will_spend') : t('ads.launch.approvals.card.will_not_spend') }}
                </p>
                <h3 class="pt-1 font-semibold">{{ t('ads.launch.approvals.card.landing') }}</h3>
                <p dir="ltr" class="break-all text-start">{{ launch.link }}</p>
                <p v-if="launch.product">
                    {{ t('ads.launch.approvals.card.price', { price: launch.product.prices.join(' / ') }) }} ·
                    {{ t('ads.launch.approvals.card.stock', { n: launch.product.inventory }) }}
                </p>
            </section>
        </div>

        <ChecksPanel v-model:acked="acked" :checks="launch.checks" :ackable="canApprove" />

        <details v-if="launch.publications?.length || launch.history?.length" class="text-xs">
            <summary class="cursor-pointer font-medium">{{ t('ads.launch.approvals.card.history') }}</summary>
            <ul v-if="launch.publications?.length" class="mt-1 space-y-0.5">
                <li v-for="p in launch.publications" :key="p.id" class="flex flex-wrap items-center gap-2">
                    <span class="font-mono">{{ p.ad_name }}</span>
                    <span class="text-muted-foreground">{{ p.effective_status ?? p.ad_status ?? p.status }}</span>
                    <a v-if="p.manager_url" :href="p.manager_url" target="_blank" rel="noopener" class="text-primary hover:underline">{{
                        t('ads.launch.approvals.card.open_manager')
                    }}</a>
                </li>
            </ul>
            <ol v-if="launch.history?.length" class="mt-2 space-y-0.5 border-s ps-3">
                <li v-for="(h, i) in launch.history" :key="i">
                    <RelativeTime :iso="h.at" mode="datetime" /> · {{ t(`ads.launch.history.${h.action.replace('launch.', '')}`) }}
                    <template v-if="h.actor"> · {{ h.actor }}</template>
                    <template v-if="h.code"> · {{ t(`ads.launch.reason.${h.code}`) }}</template>
                    <template v-if="h.reason">: {{ h.reason }}</template>
                </li>
            </ol>
        </details>

        <!-- O1: an admin approving a launch they prepared or forwarded (the page computes `self`) -->
        <p v-if="canApprove && self" class="text-2xs text-muted-foreground">{{ t('ads.launch.approvals.card.self_note') }}</p>

        <footer v-if="canApprove" class="flex flex-wrap gap-2 border-t pt-3">
            <Button :disabled="!approvable" :loading="busy" @click="emit('approve', acked)">{{ t('ads.launch.approvals.card.approve') }}</Button>
            <Button variant="outline" :disabled="!launch.can.return || busy" @click="emit('return')">{{
                t('ads.launch.approvals.card.return')
            }}</Button>
            <Button variant="ghost" class="text-destructive" :disabled="!launch.can.reject || busy" @click="emit('reject')">{{
                t('ads.launch.approvals.card.reject')
            }}</Button>
        </footer>
    </article>
</template>
