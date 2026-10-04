<script setup lang="ts">
/**
 * Publish a material as PAUSED ads into an existing campaign and ad set (spec 2.2): account, campaign, ad set, page /
 * Instagram identity (remembered per account), files, captions, then a preview of the ad names and the link.
 * `captions` pre-fills the caption list (AI captions open the dialog with 3); without it one caption comes from the
 * material's notes. Campaigns, ad sets and identities are read live from the platform.
 */
import FormDialog from '@/components/crm/FormDialog.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { cn } from '@/lib/utils';
import type { MaterialRow, PublishAccount, PublishCampaign, PublishCaption, PublishIdentity } from '@/types/ads';
import { LoaderCircle, Plus, X } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps<{ open: boolean; material: MaterialRow; captions?: PublishCaption[] | null }>();
const emit = defineEmits<{ 'update:open': [open: boolean]; published: [] }>();

const api = useApi();
const toast = useToast();
const { t } = useI18n();

const CTAS = ['SHOP_NOW', 'LEARN_MORE', 'ORDER_NOW', 'SEND_MESSAGE'] as const;
const MAX_CAPTIONS = 5;
const TYPE_LABELS: Record<string, string> = { reel: 'Reel', carousel: 'Carousel', post: 'Post', story: 'Story', image: 'Image', video: 'Video' };

const accounts = ref<PublishAccount[]>([]);
const link = ref<string | null>(null);
const campaigns = ref<PublishCampaign[]>([]);
const identities = ref<PublishIdentity[]>([]);
const loading = ref(false);
const loadingAccount = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);

const form = reactive({
    account_id: null as number | null,
    campaign_id: '',
    adset_id: '',
    page_id: '',
    file_ids: [] as number[],
});
const captions = ref<PublishCaption[]>([]);

const files = computed(() => props.material.files ?? []);
const campaign = computed(() => campaigns.value.find((c) => c.id === form.campaign_id) ?? null);
const adset = computed(() => campaign.value?.adsets.find((s) => s.id === form.adset_id) ?? null);
const identity = computed(() => identities.value.find((i) => i.page_id === form.page_id) ?? null);

function defaultCaptions(): PublishCaption[] {
    if (props.captions?.length) return props.captions.map((c) => ({ ...c }));
    return [{ headline: props.material.title.slice(0, 40), primary_text: props.material.content_notes ?? '', cta: 'SHOP_NOW' }];
}

/** Same rule as the server: the material's first type, else Reel for a video file and Image for the rest. */
function typeFor(fileId: number): string {
    const first = (props.material.types[0] ?? '').toLowerCase();
    if (TYPE_LABELS[first]) return TYPE_LABELS[first];
    return files.value.find((f) => f.id === fileId)?.mime?.startsWith('video/') ? 'Reel' : 'Image';
}
const previewNames = computed(() =>
    form.file_ids.flatMap((id) => captions.value.map((_, i) => `M${props.material.id} | ${typeFor(id)} | C${i + 1}`)),
);
const urlTags = computed(() => {
    const p = accounts.value.find((a) => a.id === form.account_id)?.platform;
    return p === 'tiktok'
        ? 'utm_source=tiktok&utm_medium=paid&utm_campaign=__CAMPAIGN_NAME__&utm_content=__CID__'
        : 'utm_source=meta&utm_medium=paid&utm_campaign={{campaign.name}}&utm_content={{ad.id}}';
});

const captionsValid = computed(() => captions.value.length > 0 && captions.value.every((c) => c.headline.trim() && c.primary_text.trim()));
const canSubmit = computed(
    () => !!form.account_id && !!form.campaign_id && !!form.adset_id && !!identity.value && form.file_ids.length > 0 && captionsValid.value && !!link.value,
);

async function loadAccounts(): Promise<void> {
    loading.value = true;
    try {
        const { data } = await api.get<{ accounts: PublishAccount[]; link: string | null }>('/ads/publish/options', { params: { material: props.material.id } });
        accounts.value = data.accounts;
        link.value = data.link;
        if (data.accounts.length === 1) form.account_id = data.accounts[0].id;
    } catch (e) {
        error.value = apiErrorMessage(e, t('ads.publish.load_failed'));
    } finally {
        loading.value = false;
    }
}

async function loadAccount(id: number): Promise<void> {
    loadingAccount.value = true;
    error.value = null;
    campaigns.value = [];
    identities.value = [];
    form.campaign_id = '';
    form.adset_id = '';
    form.page_id = '';
    try {
        const { data } = await api.get<{ campaigns: PublishCampaign[]; identities: PublishIdentity[]; last_identity: PublishIdentity | null }>('/ads/publish/options', {
            params: { account: id },
        });
        if (form.account_id !== id) return;
        campaigns.value = data.campaigns;
        identities.value = data.identities;
        const remembered = data.identities.find((i) => i.page_id === data.last_identity?.page_id);
        form.page_id = (remembered ?? data.identities[0])?.page_id ?? '';
    } catch (e) {
        error.value = apiErrorMessage(e, t('ads.publish.load_failed'));
    } finally {
        loadingAccount.value = false;
    }
}

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        error.value = null;
        form.file_ids = files.value.map((f) => f.id);
        captions.value = defaultCaptions();
        if (!accounts.value.length) void loadAccounts();
    },
    { immediate: true },
);
watch(
    () => props.captions,
    () => {
        if (props.open) captions.value = defaultCaptions();
    },
);
watch(
    () => form.account_id,
    (id) => {
        if (id) void loadAccount(id);
    },
);
watch(
    () => form.campaign_id,
    () => (form.adset_id = ''),
);

function addCaption(): void {
    if (captions.value.length < MAX_CAPTIONS) captions.value.push({ headline: '', primary_text: '', cta: 'SHOP_NOW' });
}
function removeCaption(i: number): void {
    if (captions.value.length > 1) captions.value.splice(i, 1);
}
function toggleFile(id: number, on: boolean): void {
    form.file_ids = on ? [...form.file_ids, id] : form.file_ids.filter((x) => x !== id);
}

async function submit(): Promise<void> {
    if (!canSubmit.value || !campaign.value || !adset.value || !identity.value) return;
    busy.value = true;
    error.value = null;
    try {
        const { data } = await api.post<{ message: string }>(`/ads/materials/${props.material.id}/publish`, {
            account_id: form.account_id,
            campaign_id: campaign.value.id,
            campaign_name: campaign.value.name,
            adset_id: adset.value.id,
            adset_name: adset.value.name,
            identity: { page_id: identity.value.page_id, page_name: identity.value.page_name, instagram_id: identity.value.instagram_id },
            file_ids: form.file_ids,
            captions: captions.value.map((c) => ({ headline: c.headline.trim(), primary_text: c.primary_text.trim(), cta: c.cta })),
        });
        toast.push(data.message || t('ads.publish.queued'));
        emit('published');
        emit('update:open', false);
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        busy.value = false;
    }
}

const field = 'h-9 w-full rounded-md border border-input bg-background px-2 text-sm disabled:opacity-60';
const area = 'min-h-20 w-full rounded-md border border-input bg-background px-2 py-1.5 text-sm';
</script>

<template>
    <FormDialog
        :open="open"
        :title="t('ads.publish.title')"
        :description="t('ads.publish.description')"
        :submit-label="t('ads.publish.submit')"
        :busy="busy"
        :disabled="!canSubmit"
        :error="error"
        wide
        @update:open="emit('update:open', $event)"
        @submit="submit"
    >
        <p v-if="loading" class="flex items-center gap-2 text-xs text-muted-foreground">
            <LoaderCircle class="size-3.5 animate-spin" aria-hidden="true" />{{ t('ads.publish.loading') }}
        </p>
        <p v-else-if="!accounts.length" class="text-xs text-muted-foreground">{{ t('ads.publish.no_accounts') }}</p>
        <p v-if="!loading && !link" role="alert" class="rounded-md bg-warning/20 px-3 py-2 text-xs text-amber-900 dark:text-amber-100">
            {{ t('ads.publish.no_link') }}
        </p>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="space-y-1">
                <label class="text-xs font-medium" for="pub-account">{{ t('ads.publish.account') }}</label>
                <select id="pub-account" v-model="form.account_id" :class="field">
                    <option :value="null">{{ t('ads.publish.pick') }}</option>
                    <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                </select>
            </div>
            <div class="space-y-1">
                <label class="flex items-center gap-1.5 text-xs font-medium" for="pub-identity">
                    {{ t('ads.publish.identity') }}
                    <LoaderCircle v-if="loadingAccount" class="size-3 animate-spin text-muted-foreground" aria-hidden="true" />
                </label>
                <select id="pub-identity" v-model="form.page_id" :class="field" :disabled="!identities.length">
                    <option v-for="i in identities" :key="i.page_id" :value="i.page_id">{{ i.page_name }}</option>
                </select>
                <p v-if="form.account_id && !loadingAccount && !identities.length" class="text-2xs text-muted-foreground">{{ t('ads.publish.no_identities') }}</p>
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium" for="pub-campaign">{{ t('ads.publish.campaign') }}</label>
                <select id="pub-campaign" v-model="form.campaign_id" :class="field" :disabled="!campaigns.length">
                    <option value="">{{ t('ads.publish.pick') }}</option>
                    <option v-for="c in campaigns" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <p v-if="form.account_id && !loadingAccount && !campaigns.length" class="text-2xs text-muted-foreground">{{ t('ads.publish.no_campaigns') }}</p>
                <p v-else-if="campaign && !campaign.naming_ok" class="text-2xs text-amber-800 dark:text-amber-200">{{ t('ads.publish.naming_off') }}</p>
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium" for="pub-adset">{{ t('ads.publish.adset') }}</label>
                <select id="pub-adset" v-model="form.adset_id" :class="field" :disabled="!campaign">
                    <option value="">{{ t('ads.publish.pick') }}</option>
                    <option v-for="s in campaign?.adsets ?? []" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
                <p v-if="campaign && !campaign.adsets.length" class="text-2xs text-muted-foreground">{{ t('ads.publish.no_adsets') }}</p>
                <p v-else-if="adset && !adset.naming_ok" class="text-2xs text-amber-800 dark:text-amber-200">{{ t('ads.publish.naming_off') }}</p>
            </div>
        </div>

        <fieldset v-if="files.length" class="space-y-1">
            <legend class="text-xs font-medium">{{ t('ads.publish.files') }}</legend>
            <ul class="flex flex-wrap gap-2">
                <li v-for="f in files" :key="f.id">
                    <label class="flex cursor-pointer items-center gap-2 rounded-md border border-border px-2 py-1 text-xs hover:bg-muted">
                        <input type="checkbox" :checked="form.file_ids.includes(f.id)" @change="toggleFile(f.id, ($event.target as HTMLInputElement).checked)" />
                        <img v-if="f.thumb_url" :src="f.thumb_url" alt="" class="size-8 rounded object-cover" loading="lazy" />
                        <span class="max-w-40 truncate" dir="auto">{{ f.original_name ?? `#${f.id}` }}</span>
                    </label>
                </li>
            </ul>
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="text-xs font-medium">{{ t('ads.publish.captions') }}</legend>
            <div v-for="(c, i) in captions" :key="i" class="space-y-2 rounded-md border border-border/70 p-2.5">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold">{{ t('ads.publish.caption_n', { n: i + 1 }) }}</p>
                    <button
                        v-if="captions.length > 1"
                        type="button"
                        class="inline-flex size-6 items-center justify-center rounded hover:bg-muted hover:text-destructive"
                        :aria-label="t('ads.publish.remove_caption', { n: i + 1 })"
                        @click="removeCaption(i)"
                    >
                        <X class="size-3.5" aria-hidden="true" />
                    </button>
                </div>
                <div class="grid gap-2 sm:grid-cols-[1fr_10rem]">
                    <div class="space-y-1">
                        <label class="text-2xs text-muted-foreground" :for="`pub-h-${i}`">{{ t('ads.publish.headline') }}</label>
                        <input :id="`pub-h-${i}`" v-model="c.headline" type="text" maxlength="255" dir="auto" :class="field" />
                    </div>
                    <div class="space-y-1">
                        <label class="text-2xs text-muted-foreground" :for="`pub-c-${i}`">{{ t('ads.publish.cta') }}</label>
                        <select :id="`pub-c-${i}`" v-model="c.cta" :class="field">
                            <option v-for="o in CTAS" :key="o" :value="o">{{ t(`ads.publish.cta_options.${o}`) }}</option>
                        </select>
                    </div>
                </div>
                <div class="space-y-1">
                    <label class="text-2xs text-muted-foreground" :for="`pub-t-${i}`">{{ t('ads.publish.primary_text') }}</label>
                    <textarea :id="`pub-t-${i}`" v-model="c.primary_text" maxlength="2000" dir="auto" :class="area" />
                </div>
            </div>
            <button
                v-if="captions.length < MAX_CAPTIONS"
                type="button"
                :class="cn('inline-flex items-center gap-1 text-xs text-primary hover:underline')"
                @click="addCaption"
            >
                <Plus class="size-3.5" aria-hidden="true" />{{ t('ads.publish.add_caption') }}
            </button>
        </fieldset>

        <section v-if="previewNames.length" class="space-y-1 rounded-md bg-muted/50 p-3 text-xs" aria-live="polite">
            <p class="font-medium">{{ t('ads.publish.preview') }} <span class="text-muted-foreground">({{ t('ads.publish.count', { n: previewNames.length }) }})</span></p>
            <ul class="space-y-0.5" dir="ltr">
                <li v-for="(n, i) in previewNames" :key="i" class="font-mono text-2xs">{{ n }}</li>
            </ul>
            <p v-if="link" class="break-all" dir="ltr"><span class="text-muted-foreground" dir="auto">{{ t('ads.publish.link') }}:</span> {{ link }}</p>
            <p class="break-all text-2xs text-muted-foreground" dir="ltr">{{ t('ads.publish.url_tags') }}: {{ urlTags }}</p>
        </section>
    </FormDialog>
</template>
