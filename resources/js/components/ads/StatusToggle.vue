<script setup lang="ts">
/**
 * Stop / Run button for one campaign, ad set or ad, with the confirm dialog (account, level, name, reason).
 * Posts to /ads/actions/status; the server checks the user's scope and answers 403 / 422 with a readable message.
 * Never rendered for placeholder nodes: the caller passes a real external id only.
 */
import FormDialog from '@/components/crm/FormDialog.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { toggleTarget } from '@/lib/ads';
import type { AdsAccess } from '@/types/ads';
import { router, usePage } from '@inertiajs/vue3';
import { LoaderCircle, Pause, Play } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        accountId: number;
        account: string;
        platform: string;
        level: 'campaign' | 'adset' | 'ad';
        externalId: string;
        name: string;
        status: string | null;
        /** Written reason to start the dialog with (a stop suggestion's reasons). */
        reason?: string;
        /** The campaign or ad set above is paused: the dialog says so (the ad's own status is what Stop / Run changes). */
        parentPaused?: boolean;
        /** Server said the user may not act on this account: the button is not shown. */
        disabled?: boolean;
        /** Skip reloading the page props after a success (the caller handles it). */
        noReload?: boolean;
    }>(),
    { reason: '', parentPaused: false, disabled: false, noReload: false },
);
const emit = defineEmits<{ done: [status: 'paused' | 'active'] }>();

const { t } = useI18n();
const api = useApi();
const toast = useToast();

const page = usePage();
const canWrite = computed(() => ((page.props.ads ?? null) as AdsAccess | null)?.canWrite === true);

// Google Ads has no writer: never offer Stop / Run there.
const target = computed(() => (props.platform === 'google' ? null : toggleTarget(props.status)));
const stopping = computed(() => target.value === 'paused');

const open = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);
const reason = ref(props.reason);

const idemKey = ref('');
watch(open, (o) => {
    if (o) {
        idemKey.value = crypto.randomUUID(); // one key per dialog: a double submit replays, never a second write
        reason.value = props.reason;
        error.value = null;
    }
});

const levelLabel = computed(() => t(`ads.campaigns.level_${props.level}`));
const platformLabel = computed(() => (props.platform === 'tiktok' ? 'TikTok' : props.platform === 'google' ? 'Google' : 'Meta'));

async function submit(): Promise<void> {
    if (busy.value || !target.value) return;
    busy.value = true;
    error.value = null;
    try {
        const res = await api.post(
            '/ads/actions/status',
            { account_id: props.accountId, level: props.level, external_id: props.externalId, status: target.value, reason: reason.value.trim() || null },
            { headers: { 'Idempotency-Key': idemKey.value } },
        );
        open.value = false;
        if (res.data?.ok === false) {
            // 202: a Stop retry is scheduled or the outcome is not known yet; the log shows it as in progress.
            toast.push(String(res.data.message ?? t('ads.actions.pending')), 'info');
            if (!props.noReload) router.reload();
            return;
        }
        toast.push(t(stopping.value ? 'ads.actions.stopped' : 'ads.actions.resumed'), 'success');
        emit('done', target.value);
        if (!props.noReload) router.reload();
    } catch (e) {
        error.value = apiErrorMessage(e, t('ads.actions.failed'));
    } finally {
        busy.value = false;
    }
}

const btn =
    'inline-flex h-7 items-center gap-1 rounded-md border px-2 text-2xs font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-50';
</script>

<template>
    <span v-if="target && !disabled && canWrite" class="inline-flex">
        <button
            type="button"
            :class="[btn, stopping ? 'border-destructive/40 text-destructive hover:bg-destructive/10' : 'border-border text-foreground hover:bg-muted']"
            :aria-label="t(stopping ? 'ads.actions.stop_aria' : 'ads.actions.run_aria', { name })"
            :disabled="busy"
            @click.stop="open = true"
            @keydown.stop
        >
            <LoaderCircle v-if="busy" class="size-3.5 animate-spin" aria-hidden="true" />
            <Pause v-else-if="stopping" class="size-3.5" aria-hidden="true" />
            <Play v-else class="rtl-flip size-3.5" aria-hidden="true" />
            {{ t(stopping ? 'ads.actions.stop' : 'ads.actions.run') }}
        </button>

        <FormDialog
            v-model:open="open"
            :title="t(stopping ? 'ads.actions.stop_title' : 'ads.actions.run_title', { level: levelLabel })"
            :description="t(stopping ? 'ads.actions.stop_body' : 'ads.actions.run_body', { platform: platformLabel })"
            :busy="busy"
            :error="error"
            :destructive="stopping"
            :submit-label="t(stopping ? 'ads.actions.confirm_stop' : 'ads.actions.confirm_run')"
            @submit="submit"
        >
            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-xs">
                <dt class="text-muted-foreground">{{ t('ads.actions.account') }}</dt>
                <dd class="font-medium" dir="auto">{{ account }}</dd>
                <dt class="text-muted-foreground">{{ t('ads.actions.level') }}</dt>
                <dd class="font-medium">{{ levelLabel }}</dd>
                <dt class="text-muted-foreground">{{ t('ads.actions.name') }}</dt>
                <dd class="break-words font-medium" dir="auto">{{ name }}</dd>
            </dl>
            <p v-if="parentPaused" class="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">{{ t('ads.actions.parent_paused') }}</p>
            <label class="block space-y-1 text-xs">
                <span class="font-medium">{{ t('ads.actions.reason') }}</span>
                <textarea
                    v-model="reason"
                    rows="3"
                    maxlength="1000"
                    dir="auto"
                    :placeholder="t('ads.actions.reason_hint')"
                    class="w-full rounded-md border border-input bg-background px-2 py-1.5 text-xs"
                />
            </label>
        </FormDialog>
    </span>
</template>
