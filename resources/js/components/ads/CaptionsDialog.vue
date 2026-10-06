<script setup lang="ts">
/**
 * AI captions for a material's video (spec Part 3): pick the video, Generate (or Regenerate) three Egyptian-Arabic captions
 * (emotional, offer, quality) from its frames and the product data, edit them by hand, and (media buyers and supervisors)
 * open the publish dialog with the three captions through "Create 3 ads".
 */
import { Button, buttonVariants } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import type { MaterialCaption, MaterialRow, PublishCaption } from '@/types/ads';
import { Rocket, Sparkles } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ open: boolean; material: MaterialRow; canPublish: boolean }>();
const emit = defineEmits<{ 'update:open': [open: boolean]; create: [captions: PublishCaption[], fileId: number] }>();

const api = useApi();
const toast = useToast();
const { t } = useI18n();

const CTAS = ['SHOP_NOW', 'LEARN_MORE', 'ORDER_NOW', 'SEND_MESSAGE'] as const;
const MAX_HEADLINE = 40;
const MAX_TEXT = 400;

const videos = computed(() => (props.material.files ?? []).filter((f) => f.mime?.startsWith('video/')));
const fileId = ref<number | null>(null);
const file = computed(() => videos.value.find((f) => f.id === fileId.value) ?? null);
const captions = ref<MaterialCaption[]>([]);
const saved = ref<Record<number, string>>({});
const loading = ref(false);
const generating = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);
const framesUsed = ref<number | null>(null);

const signature = (c: MaterialCaption): string => JSON.stringify([c.headline, c.primary_text, c.cta]);
const dirty = computed(() => captions.value.filter((c) => saved.value[c.id] !== signature(c)));
const valid = computed(() => captions.value.length === 3 && captions.value.every((c) => c.headline.trim() && c.primary_text.trim()));
const busy = computed(() => loading.value || generating.value || saving.value);

function setCaptions(rows: MaterialCaption[]): void {
    captions.value = rows.map((c) => ({ ...c }));
    saved.value = Object.fromEntries(rows.map((c) => [c.id, signature(c)]));
}

async function load(): Promise<void> {
    if (!fileId.value) return;
    loading.value = true;
    error.value = null;
    framesUsed.value = null;
    try {
        const { data } = await api.get<{ captions: MaterialCaption[] }>(`/ads/materials/${props.material.id}/captions`, { params: { file_id: fileId.value } });
        setCaptions(data.captions);
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        loading.value = false;
    }
}

async function generate(): Promise<void> {
    if (!fileId.value) return;
    generating.value = true;
    error.value = null;
    try {
        const { data } = await api.post<{ captions: MaterialCaption[]; frames_used: number | null }>(`/ads/materials/${props.material.id}/captions`, { file_id: fileId.value });
        setCaptions(data.captions);
        framesUsed.value = data.frames_used;
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        generating.value = false;
    }
}

async function saveDirty(): Promise<boolean> {
    saving.value = true;
    error.value = null;
    try {
        for (const c of dirty.value) {
            await api.put(`/ads/captions/${c.id}`, { headline: c.headline.trim(), primary_text: c.primary_text.trim(), cta: c.cta });
            saved.value[c.id] = signature(c);
        }
        return true;
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
        return false;
    } finally {
        saving.value = false;
    }
}

async function save(): Promise<void> {
    if (await saveDirty()) toast.push(t('ads.captions.saved'));
}

async function createAds(): Promise<void> {
    if (!valid.value || !(await saveDirty())) return;
    emit(
        'create',
        captions.value.map((c) => ({ headline: c.headline.trim(), primary_text: c.primary_text.trim(), cta: c.cta })),
        fileId.value as number,
    );
    emit('update:open', false);
}

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        fileId.value = videos.value[0]?.id ?? null;
        captions.value = [];
        void load();
    },
    { immediate: true },
);
watch(fileId, () => {
    if (props.open) void load();
});

const field = 'h-9 w-full rounded-md border border-input bg-background px-2 text-sm disabled:opacity-60';
const area = 'min-h-24 w-full rounded-md border border-input bg-background px-2 py-1.5 text-sm';
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader class="text-start">
                <DialogTitle class="text-base">{{ t('ads.captions.title') }}</DialogTitle>
                <DialogDescription class="text-xs">{{ t('ads.captions.description') }}</DialogDescription>
            </DialogHeader>

            <div class="space-y-3 text-sm">
                <p v-if="!videos.length" class="text-xs text-muted-foreground">{{ t('ads.captions.no_video') }}</p>
                <div v-else class="flex flex-wrap items-center gap-3">
                    <img v-if="file?.thumb_url" :src="file.thumb_url" alt="" class="h-20 w-14 rounded-md border border-border object-cover" />
                    <div v-if="videos.length > 1" class="space-y-1">
                        <label class="text-xs font-medium" for="cap-file">{{ t('ads.captions.video') }}</label>
                        <select id="cap-file" v-model="fileId" :class="field" :disabled="busy">
                            <option v-for="v in videos" :key="v.id" :value="v.id">{{ v.original_name ?? `#${v.id}` }}</option>
                        </select>
                    </div>
                    <Button :variant="captions.length ? 'outline' : 'default'" class="ms-auto" :loading="generating" :disabled="busy || !fileId" @click="generate">
                        <Sparkles class="size-4" aria-hidden="true" />
                        {{ captions.length ? t('ads.captions.regenerate') : t('ads.captions.generate') }}
                    </Button>
                </div>

                <p v-if="generating" class="text-xs text-muted-foreground" role="status">{{ t('ads.captions.working') }}</p>
                <p v-if="framesUsed === 0" role="status" class="rounded-md bg-warning/20 px-3 py-2 text-xs text-amber-900 dark:text-amber-100">{{ t('ads.captions.no_frames') }}</p>
                <p v-else-if="framesUsed" class="text-2xs text-muted-foreground">{{ t('ads.captions.frames_used', { n: framesUsed }) }}</p>

                <div v-for="(c, i) in captions" :key="c.id" class="space-y-2 rounded-md border border-border/70 p-2.5">
                    <p class="text-xs font-semibold">{{ t('ads.captions.angle_n', { n: i + 1, angle: t(`ads.captions.angles.${c.angle}`) }) }}</p>
                    <div class="grid gap-2 sm:grid-cols-[1fr_10rem]">
                        <div class="space-y-1">
                            <label class="flex justify-between text-2xs text-muted-foreground" :for="`cap-h-${c.id}`">
                                <span>{{ t('ads.publish.headline') }}</span><span>{{ c.headline.length }}/{{ MAX_HEADLINE }}</span>
                            </label>
                            <input :id="`cap-h-${c.id}`" v-model="c.headline" type="text" :maxlength="MAX_HEADLINE" dir="auto" :class="field" />
                        </div>
                        <div class="space-y-1">
                            <label class="text-2xs text-muted-foreground" :for="`cap-c-${c.id}`">{{ t('ads.publish.cta') }}</label>
                            <select :id="`cap-c-${c.id}`" v-model="c.cta" :class="field">
                                <option v-for="o in CTAS" :key="o" :value="o">{{ t(`ads.publish.cta_options.${o}`) }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="space-y-1">
                        <label class="flex justify-between text-2xs text-muted-foreground" :for="`cap-t-${c.id}`">
                            <span>{{ t('ads.publish.primary_text') }}</span><span>{{ c.primary_text.length }}/{{ MAX_TEXT }}</span>
                        </label>
                        <textarea :id="`cap-t-${c.id}`" v-model="c.primary_text" :maxlength="MAX_TEXT" dir="auto" :class="area" />
                    </div>
                </div>
            </div>

            <p v-if="error" role="alert" class="rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">{{ error }}</p>

            <DialogFooter class="gap-2 sm:justify-start">
                <Button v-if="canPublish" :loading="saving" :disabled="busy || !valid" @click="createAds">
                    <Rocket class="size-4" aria-hidden="true" />
                    {{ t('ads.captions.create_ads') }}
                </Button>
                <button type="button" :class="buttonVariants({ variant: canPublish ? 'outline' : 'default' })" :disabled="busy || !dirty.length" @click="save">
                    {{ t('ads.captions.save') }}
                </button>
                <button type="button" :class="buttonVariants({ variant: 'outline' })" @click="emit('update:open', false)">{{ t('common.close') }}</button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
