<script setup lang="ts">
/** One-click Stop / Run (U 3.1: always visible, never in a menu) opening the server-diff dialog. Google has no writer. */
import WriteActionDialog from '@/components/ads/WriteActionDialog.vue';
import { useI18n } from '@/composables/useI18n';
import { toggleTarget } from '@/lib/ads';
import type { AdLevel, AdsAccess } from '@/types/ads';
import { router, usePage } from '@inertiajs/vue3';
import { Pause, Play } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        accountId: number;
        account: string;
        platform: string;
        level: AdLevel;
        externalId: string;
        name: string;
        status: string | null;
        canWrite: boolean;
        spendToday?: number | null;
        dataAt?: string | null;
        currency?: string;
        reason?: string;
        parentPaused?: boolean;
        size?: 'sm' | 'md';
        noReload?: boolean;
    }>(),
    { spendToday: null, dataAt: null, currency: 'EGP', reason: '', parentPaused: false, size: 'sm', noReload: false },
);
const emit = defineEmits<{ done: [status: 'active' | 'paused'] }>();
const { t } = useI18n();
const page = usePage();
const allowed = computed(() => ((page.props.ads ?? null) as AdsAccess | null)?.canWrite === true && props.canWrite);
const target = computed(() => (props.platform === 'google' ? null : toggleTarget(props.status)));
const stopping = computed(() => target.value === 'paused');
const open = ref(false);

function done(status: 'active' | 'paused'): void {
    emit('done', status);
    if (!props.noReload) router.reload();
}
</script>

<template>
    <span v-if="target && allowed" class="inline-flex">
        <button
            type="button"
            class="inline-flex shrink-0 items-center gap-1 rounded-md border font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-ring"
            :class="[
                size === 'md' ? 'h-11 px-4 text-sm' : 'h-7 px-2 text-2xs',
                stopping ? 'border-destructive/40 text-destructive hover:bg-destructive/10' : 'border-border text-foreground hover:bg-muted',
            ]"
            :aria-label="t(stopping ? 'ads.actions.stop_aria' : 'ads.actions.run_aria', { name })"
            @click.stop="open = true"
        >
            <Pause v-if="stopping" class="size-3.5" aria-hidden="true" />
            <Play v-else class="rtl-flip size-3.5" aria-hidden="true" />
            {{ t(stopping ? 'ads.control.write.stop' : 'ads.control.write.run') }}
        </button>
        <WriteActionDialog
            v-model:open="open"
            :account-id="accountId"
            :account="account"
            :platform="platform"
            :level="level"
            :external-id="externalId"
            :name="name"
            :to="target"
            :spend-today="spendToday"
            :data-at="dataAt"
            :currency="currency"
            :reason="reason"
            :parent-paused="parentPaused"
            @done="done"
        />
    </span>
</template>
