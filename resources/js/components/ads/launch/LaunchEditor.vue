<script setup lang="ts">
/**
 * The launch draft editor (spec 3.5, D1 / D2). Content: slot, files, captions, CTA, live checks, «ابعتي للميديا باير».
 * Buyer (review): the same fields with content's original version for the diff, «ابعتي للمدير», «رجّعيها للكونتنت».
 * Budget, link, targeting and objective are never inputs (the server refuses them anyway).
 */
import ChecksPanel from '@/components/ads/launch/ChecksPanel.vue';
import ReasonDialog from '@/components/ads/launch/ReasonDialog.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import type { CheckRow, LaunchAnswer, LaunchCaption, LaunchOptions, LaunchRow } from '@/types/ads';
import { isAxiosError } from 'axios';
import { Plus, Sparkles, X } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        open: boolean;
        material: { id: number; title: string };
        launch?: LaunchRow | null;
        mode: 'content' | 'buyer';
        reasons?: string[];
    }>(),
    { launch: null, reasons: () => ['caption_wrong', 'price_wrong', 'media_quality', 'wrong_adset', 'off_brand', 'out_of_stock', 'other'] },
);
const emit = defineEmits<{ 'update:open': [open: boolean]; saved: [launch: LaunchRow] }>();

const api = useApi();
const toast = useToast();
const { t } = useI18n();

type Busy = 'save' | 'submit' | 'forward' | 'send_back' | 'withdraw' | null;
const options = ref<LaunchOptions | null>(null);
const current = ref<LaunchRow | null>(null);
const checks = ref<CheckRow[] | null>(null);
const checking = ref(false);
const busy = ref<Busy>(null);
const error = ref<string | null>(null);
const reasonOpen = ref(false);
const aiOpen = ref(false);
const form = reactive({ adset_id: null as number | null, file_ids: [] as number[], captions: [] as LaunchCaption[] });
const snapshot = ref('');

const serialised = () => JSON.stringify({ a: form.adset_id, f: form.file_ids, c: form.captions });
const dirty = computed(() => serialised() !== snapshot.value);
const maxCaptions = computed(() => options.value?.max_captions ?? 5);
const ctas = computed(() => options.value?.ctas ?? ['SHOP_NOW', 'LEARN_MORE', 'ORDER_NOW', 'SEND_MESSAGE']);
const slot = computed(() => options.value?.slots.find((s) => s.id === form.adset_id) ?? null);
const adsCount = computed(() => form.file_ids.length * form.captions.length);
const valid = computed(
    () =>
        form.adset_id !== null &&
        form.file_ids.length > 0 &&
        form.captions.length > 0 &&
        form.captions.every((c) => c.headline.trim() && c.primary_text.trim()),
);
const can = computed(() => current.value?.can ?? null);
const original = computed(() => (props.mode === 'buyer' ? (current.value?.original ?? null) : null));
const title = computed(() =>
    props.mode === 'buyer'
        ? t('ads.launch.editor.title_review')
        : current.value
          ? t('ads.launch.editor.title_edit')
          : t('ads.launch.editor.title_new', { title: props.material.title }),
);

function changed(i: number): boolean {
    const o = original.value?.captions[i];
    const c = form.captions[i];
    return !!original.value && (!o || !c || o.headline !== c.headline || o.primary_text !== c.primary_text || o.cta !== c.cta);
}

function fill(l: LaunchRow | null): void {
    current.value = l;
    form.adset_id = l?.adset.id ?? null;
    form.file_ids = l ? [...l.file_ids] : (options.value?.files.slice(0, 1).map((f) => f.id) ?? []);
    form.captions = l ? l.captions.map((c) => ({ ...c })) : [{ headline: props.material.title.slice(0, 40), primary_text: '', cta: 'SHOP_NOW' }];
    snapshot.value = serialised();
}

async function runChecks(): Promise<void> {
    if (!current.value) return;
    checking.value = true;
    try {
        const { data } = await api.get<{ checks: CheckRow[] }>(`/ads/launches/${current.value.id}/checks`, { silent: true });
        checks.value = data.checks;
    } catch {
        checks.value = null;
    } finally {
        checking.value = false;
    }
}

async function load(): Promise<void> {
    error.value = null;
    checks.value = null;
    options.value = null;
    try {
        const { data } = await api.get<LaunchOptions>('/ads/launches/options', { params: { material: props.material.id } });
        options.value = data;
        fill(props.launch);
        await runChecks();
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    }
}

watch(
    () => props.open,
    (o) => {
        if (o) void load();
    },
    { immediate: true },
);

async function save(): Promise<LaunchAnswer> {
    const body = { adset_id: form.adset_id, file_ids: form.file_ids, captions: form.captions };
    const { data } = current.value
        ? await api.put<LaunchAnswer>(`/ads/launches/${current.value.id}`, { ...body, revision: current.value.revision })
        : await api.post<LaunchAnswer>(`/ads/materials/${props.material.id}/launches`, body);
    fill(data.launch);
    emit('saved', data.launch);
    return data;
}

function failure(e: unknown): void {
    const details = isAxiosError(e) ? (e.response?.data as { details?: { checks?: CheckRow[] } } | undefined)?.details : undefined;
    if (details?.checks) checks.value = details.checks;
    error.value = apiErrorMessage(e, t('common.error'));
}

async function act(kind: 'save' | 'submit' | 'forward'): Promise<void> {
    busy.value = kind;
    error.value = null;
    try {
        let message = '';
        if (!current.value || dirty.value) message = (await save()).message;
        if (kind === 'save') {
            await runChecks();
            toast.push(message || t('ads.launch.checks.title'));
            return;
        }
        const { data } = await api.post<LaunchAnswer>(`/ads/launches/${current.value!.id}/${kind}`, { revision: current.value!.revision });
        toast.push(data.message);
        emit('saved', data.launch);
        emit('update:open', false);
    } catch (e) {
        failure(e);
    } finally {
        busy.value = null;
    }
}

async function sendBack(payload: { code: string; text: string }): Promise<void> {
    if (!current.value) return;
    busy.value = 'send_back';
    try {
        const { data } = await api.post<LaunchAnswer>(`/ads/launches/${current.value.id}/send-back`, payload);
        toast.push(data.message);
        reasonOpen.value = false;
        emit('saved', data.launch);
        emit('update:open', false);
    } catch (e) {
        failure(e);
    } finally {
        busy.value = null;
    }
}

async function withdraw(): Promise<void> {
    if (!current.value || !window.confirm(t('ads.launch.actions.withdraw_body'))) return;
    busy.value = 'withdraw';
    try {
        const { data } = await api.post<LaunchAnswer>(`/ads/launches/${current.value.id}/withdraw`);
        toast.push(data.message);
        emit('saved', data.launch);
        emit('update:open', false);
    } catch (e) {
        failure(e);
    } finally {
        busy.value = null;
    }
}

function addCaption(c?: LaunchCaption): void {
    if (form.captions.length >= maxCaptions.value) return;
    form.captions.push(c ? { headline: c.headline, primary_text: c.primary_text, cta: c.cta } : { headline: '', primary_text: '', cta: 'SHOP_NOW' });
}

function toggleFile(id: number, on: boolean): void {
    form.file_ids = on ? [...new Set([...form.file_ids, id])] : form.file_ids.filter((x) => x !== id);
}

const field = 'w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring';
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-h-[92svh] overflow-y-auto sm:max-w-4xl">
            <DialogHeader class="text-start">
                <DialogTitle class="text-base">{{ title }}</DialogTitle>
                <DialogDescription class="text-xs">{{ material.title }}</DialogDescription>
            </DialogHeader>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
                <div class="space-y-4">
                    <!-- slot -->
                    <label class="block space-y-1">
                        <span class="text-xs font-medium">{{ t('ads.launch.editor.slot') }}</span>
                        <select v-model.number="form.adset_id" :class="field" :disabled="!options">
                            <option :value="null" disabled>{{ t('ads.launch.editor.slot_placeholder') }}</option>
                            <option v-for="s in options?.slots ?? []" :key="s.id" :value="s.id">{{ s.account.name }} · {{ s.name }}</option>
                        </select>
                        <span v-if="options && !options.slots.length" class="block text-2xs text-amber-800 dark:text-amber-200">{{
                            t('ads.launch.editor.slot_none')
                        }}</span>
                        <span v-else-if="slot" class="block text-2xs text-muted-foreground">
                            {{
                                t('ads.launch.editor.slot_hint', { account: slot.account.name, campaign: slot.campaign.name, buyer: slot.buyer.name })
                            }}
                        </span>
                    </label>

                    <!-- files -->
                    <fieldset>
                        <legend class="mb-1 text-xs font-medium">{{ t('ads.launch.editor.files') }}</legend>
                        <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                            <label
                                v-for="f in options?.files ?? []"
                                :key="f.id"
                                class="relative block cursor-pointer overflow-hidden rounded-md border"
                                :class="form.file_ids.includes(f.id) ? 'border-primary ring-2 ring-primary/40' : 'border-border'"
                            >
                                <img
                                    v-if="f.thumb_url"
                                    :src="f.thumb_url"
                                    :alt="f.original_name ?? ''"
                                    class="aspect-[4/5] w-full object-cover"
                                    loading="lazy"
                                />
                                <span v-else class="flex aspect-[4/5] items-center justify-center bg-muted text-2xs text-muted-foreground">{{
                                    f.mime
                                }}</span>
                                <input
                                    type="checkbox"
                                    class="absolute start-1 top-1 size-4 accent-primary"
                                    :checked="form.file_ids.includes(f.id)"
                                    :aria-label="f.original_name ?? String(f.id)"
                                    @change="toggleFile(f.id, ($event.target as HTMLInputElement).checked)"
                                />
                            </label>
                        </div>
                    </fieldset>

                    <!-- captions -->
                    <section class="space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-xs font-medium">{{ t('ads.launch.editor.captions') }}</h3>
                            <span class="text-2xs text-muted-foreground">{{ t('ads.launch.editor.ads_count', { n: adsCount }) }}</span>
                            <button
                                v-if="options?.captions.length"
                                type="button"
                                class="ms-auto inline-flex items-center gap-1 text-xs text-primary hover:underline"
                                :aria-expanded="aiOpen"
                                @click="aiOpen = !aiOpen"
                            >
                                <Sparkles class="size-3.5" aria-hidden="true" />{{ t('ads.launch.editor.use_ai') }}
                            </button>
                        </div>
                        <ul v-if="aiOpen" class="space-y-1 rounded-md border border-dashed p-2">
                            <li v-for="(c, i) in options?.captions ?? []" :key="i">
                                <button
                                    type="button"
                                    class="w-full rounded px-2 py-1 text-start text-xs hover:bg-muted disabled:opacity-50"
                                    :disabled="form.captions.length >= maxCaptions"
                                    @click="addCaption(c)"
                                >
                                    <strong>{{ c.headline }}</strong> — {{ c.primary_text.slice(0, 90) }}
                                </button>
                            </li>
                        </ul>
                        <div
                            v-for="(c, i) in form.captions"
                            :key="i"
                            class="space-y-2 rounded-md border p-2"
                            :class="changed(i) ? 'border-warning bg-warning/5' : 'border-border'"
                        >
                            <div class="flex items-center gap-2">
                                <span class="text-2xs font-semibold text-muted-foreground">C{{ i + 1 }}</span>
                                <span v-if="changed(i)" class="text-2xs font-medium text-amber-800 dark:text-amber-200">{{
                                    t('ads.launch.approvals.card.edited')
                                }}</span>
                                <button
                                    v-if="form.captions.length > 1"
                                    type="button"
                                    class="ms-auto rounded p-1 text-muted-foreground hover:bg-muted"
                                    :aria-label="t('ads.launch.editor.remove_caption')"
                                    @click="form.captions.splice(i, 1)"
                                >
                                    <X class="size-4" aria-hidden="true" />
                                </button>
                            </div>
                            <label class="block space-y-1">
                                <span class="text-2xs">{{ t('ads.launch.editor.headline') }}</span>
                                <input v-model="c.headline" type="text" maxlength="255" :class="field" />
                            </label>
                            <label class="block space-y-1">
                                <span class="text-2xs">{{ t('ads.launch.editor.primary_text') }}</span>
                                <textarea v-model="c.primary_text" rows="3" maxlength="2000" :class="field" />
                            </label>
                            <label class="block space-y-1">
                                <span class="text-2xs">{{ t('ads.launch.editor.cta') }}</span>
                                <select v-model="c.cta" :class="field">
                                    <option v-for="cta in ctas" :key="cta" :value="cta">{{ t(`ads.launch.editor.cta_labels.${cta}`) }}</option>
                                </select>
                            </label>
                        </div>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 text-xs text-primary hover:underline disabled:opacity-50"
                            :disabled="form.captions.length >= maxCaptions"
                            @click="addCaption()"
                        >
                            <Plus class="size-3.5" aria-hidden="true" />{{ t('ads.launch.editor.add_caption') }}
                        </button>
                    </section>

                    <!-- content's original (buyer review) -->
                    <details v-if="original" class="rounded-md border border-dashed p-2 text-xs">
                        <summary class="cursor-pointer font-medium">{{ t('ads.launch.editor.original') }}</summary>
                        <p class="mt-1 text-muted-foreground">{{ t('ads.launch.editor.original_hint') }}</p>
                        <ul class="mt-1 space-y-1">
                            <li v-for="(o, i) in original.captions" :key="i">
                                <strong>{{ o.headline }}</strong> — {{ o.primary_text }}
                            </li>
                        </ul>
                    </details>

                    <p class="text-2xs text-muted-foreground">
                        {{ t('ads.launch.editor.link') }}
                        <span dir="ltr" class="break-all">{{ current?.link ?? '' }}</span>
                    </p>
                    <p v-if="mode === 'buyer'" class="text-2xs text-muted-foreground">
                        {{ t('ads.launch.editor.identity') }}: {{ current?.identity?.page_name || t('ads.launch.editor.identity_auto') }}
                    </p>
                </div>

                <aside class="space-y-2 lg:border-s lg:ps-4">
                    <h3 class="text-xs font-medium">{{ t('ads.launch.editor.checks') }}</h3>
                    <p v-if="!current" class="text-xs text-muted-foreground">{{ t('ads.launch.editor.saved_first') }}</p>
                    <ChecksPanel v-else :checks="checks" :loading="checking" />
                </aside>
            </div>

            <p v-if="error" role="alert" class="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">{{ error }}</p>

            <DialogFooter class="flex-wrap gap-2 sm:justify-start">
                <template v-if="mode === 'content'">
                    <Button
                        :loading="busy === 'submit'"
                        :disabled="!valid || busy !== null || (current !== null && !can?.submit)"
                        @click="act('submit')"
                    >
                        {{ t('ads.launch.editor.submit') }}
                    </Button>
                </template>
                <template v-else>
                    <Button :loading="busy === 'forward'" :disabled="!valid || busy !== null || !can?.forward" @click="act('forward')">
                        {{ t('ads.launch.editor.forward') }}
                    </Button>
                    <Button variant="outline" :disabled="busy !== null || !can?.send_back" @click="reasonOpen = true">{{
                        t('ads.launch.editor.send_back')
                    }}</Button>
                </template>
                <Button
                    variant="outline"
                    :loading="busy === 'save'"
                    :disabled="!valid || busy !== null || (current !== null && !can?.edit)"
                    @click="act('save')"
                >
                    {{ t('ads.launch.editor.save') }}
                </Button>
                <Button
                    v-if="can?.withdraw"
                    variant="ghost"
                    class="text-destructive"
                    :loading="busy === 'withdraw'"
                    :disabled="busy !== null"
                    @click="withdraw"
                >
                    {{ t('ads.launch.editor.withdraw') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <ReasonDialog v-model:open="reasonOpen" kind="send_back" :reasons="reasons" :busy="busy === 'send_back'" :error="error" @submit="sendBack" />
</template>
