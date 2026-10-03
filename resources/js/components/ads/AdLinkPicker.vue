<script setup lang="ts">
/**
 * Link a material to the real ads that run it (media buyers and supervisors). Searches
 * /ads/materials/ad-search (scoped server-side: a buyer only finds their own accounts' ads) and saves the
 * whole selection to POST /ads/materials/{id}/ads; the server keeps other buyers' links.
 */
import PlatformChip from '@/components/ads/PlatformChip.vue';
import { buttonVariants } from '@/components/ui/button';
import { useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { AD_PLATFORMS, safeUrl } from '@/lib/ads';
import { cn } from '@/lib/utils';
import type { AdPlatformValue, MaterialAdSearchRow, MaterialLinkedAd } from '@/types/ads';
import { router } from '@inertiajs/vue3';
import { Link2, LoaderCircle, Plus, Search, X } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<{ materialId: number; ads: MaterialLinkedAd[] }>();
const emit = defineEmits<{ saved: [] }>();

const api = useApi();
const toast = useToast();
const { t } = useI18n();

const selected = ref<MaterialLinkedAd[]>([...props.ads]);
watch(
    () => props.ads,
    (ads) => (selected.value = [...ads]),
);

const query = ref('');
const results = ref<MaterialAdSearchRow[]>([]);
const loading = ref(false);
const saving = ref(false);
let timer: number | undefined;
let seq = 0;

async function search(term: string): Promise<void> {
    const current = ++seq;
    loading.value = true;
    try {
        const { data } = await api.get<MaterialAdSearchRow[]>('/ads/materials/ad-search', { params: term ? { q: term } : {}, silent: true });
        if (current === seq) results.value = data;
    } catch {
        if (current === seq) results.value = [];
    } finally {
        if (current === seq) loading.value = false;
    }
}

watch(query, (value) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => void search(value.trim()), 300);
});
onBeforeUnmount(() => window.clearTimeout(timer));
void search('');

const isSelected = (id: number) => selected.value.some((a) => a.id === id);
function add(ad: MaterialAdSearchRow): void {
    if (!isSelected(ad.id)) selected.value = [...selected.value, { id: ad.id, name: ad.name, platform: ad.platform, status: ad.status }];
}
function remove(id: number): void {
    selected.value = selected.value.filter((a) => a.id !== id);
}

const platformOf = (p: string): AdPlatformValue | null => (AD_PLATFORMS.includes(p as AdPlatformValue) ? (p as AdPlatformValue) : null);

function save(): void {
    saving.value = true;
    router.post(
        `/ads/materials/${props.materialId}/ads`,
        { ad_ids: selected.value.map((a) => a.id) },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                toast.push(t('ads.materials.link.saved'));
                emit('saved');
            },
            onError: (errors) => toast.push(String(Object.values(errors)[0] ?? t('common.error')), 'error'),
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <div class="space-y-3">
        <div>
            <p class="mb-1.5 text-xs font-medium">{{ t('ads.materials.link.linked', { n: selected.length }) }}</p>
            <p v-if="!selected.length" class="text-xs text-muted-foreground">{{ t('ads.materials.link.none') }}</p>
            <ul v-else class="flex flex-wrap gap-1.5">
                <li
                    v-for="a in selected"
                    :key="a.id"
                    class="inline-flex max-w-full items-center gap-1 rounded-full border border-border bg-muted/40 py-0.5 pe-1 ps-2 text-2xs"
                >
                    <PlatformChip v-if="platformOf(a.platform)" :platform="platformOf(a.platform)!" size="xs" />
                    <span class="max-w-56 truncate" dir="auto">{{ a.name }}</span>
                    <button
                        type="button"
                        class="inline-flex size-5 items-center justify-center rounded-full hover:bg-background hover:text-destructive"
                        :aria-label="t('ads.materials.link.remove', { name: a.name })"
                        @click="remove(a.id)"
                    >
                        <X class="size-3" aria-hidden="true" />
                    </button>
                </li>
            </ul>
        </div>

        <div class="relative">
            <Search class="pointer-events-none absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
            <label class="sr-only" :for="`ad-search-${materialId}`">{{ t('ads.materials.link.search') }}</label>
            <input
                :id="`ad-search-${materialId}`"
                v-model="query"
                type="search"
                autocomplete="off"
                :placeholder="t('ads.materials.link.search')"
                class="flex h-9 w-full rounded-md border border-input bg-card pe-8 ps-8 text-sm placeholder:text-muted-foreground focus-visible:border-primary focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-primary"
            />
            <LoaderCircle
                v-if="loading"
                class="absolute end-2.5 top-1/2 size-3.5 -translate-y-1/2 animate-spin text-muted-foreground"
                aria-hidden="true"
            />
        </div>

        <ul class="max-h-64 space-y-1 overflow-y-auto">
            <li v-if="!loading && !results.length" class="px-2 py-2 text-xs text-muted-foreground">{{ t('ads.materials.link.no_results') }}</li>
            <li v-for="ad in results" :key="ad.id" class="flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-muted">
                <img
                    v-if="safeUrl(ad.thumbnail_url)"
                    :src="safeUrl(ad.thumbnail_url) ?? undefined"
                    alt=""
                    class="size-9 rounded object-cover"
                    loading="lazy"
                />
                <span v-else class="flex size-9 items-center justify-center rounded bg-muted"
                    ><Link2 class="size-3.5 text-muted-foreground" aria-hidden="true"
                /></span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-xs font-medium" dir="auto">{{ ad.name }}</p>
                    <p class="truncate text-2xs text-muted-foreground">
                        <span dir="auto">{{ ad.account ?? '—' }}</span> · <span dir="ltr">{{ ad.external_id }}</span>
                    </p>
                </div>
                <PlatformChip v-if="platformOf(ad.platform)" :platform="platformOf(ad.platform)!" size="xs" />
                <button
                    type="button"
                    :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'h-7 gap-1 px-2 text-2xs')"
                    :disabled="isSelected(ad.id)"
                    @click="add(ad)"
                >
                    <Plus class="size-3" aria-hidden="true" />{{ isSelected(ad.id) ? t('ads.materials.link.added') : t('ads.materials.link.add') }}
                </button>
            </li>
        </ul>

        <button type="button" :class="cn(buttonVariants({ variant: 'default', size: 'sm' }), 'gap-1.5')" :disabled="saving" @click="save">
            <LoaderCircle v-if="saving" class="size-3.5 animate-spin" aria-hidden="true" />
            <Link2 v-else class="size-3.5" aria-hidden="true" />{{ t('ads.materials.link.save') }}
        </button>
    </div>
</template>
