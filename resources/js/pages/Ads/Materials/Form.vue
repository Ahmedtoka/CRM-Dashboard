<script setup lang="ts">
/** Ads Hub — new / edit material: the content team's upload form (spec §8.7). Multipart through Inertia with a progress bar. */
import AdLinkPicker from '@/components/ads/AdLinkPicker.vue';
import MaterialFileDrop from '@/components/ads/MaterialFileDrop.vue';
import MaterialStatusChip from '@/components/ads/MaterialStatusChip.vue';
import ProductPicker from '@/components/ads/ProductPicker.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import { buttonVariants } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { links, useMaterialPermissions } from '@/lib/adsMaterials';
import { cn } from '@/lib/utils';
import type { AdsMaterialFormProps, MaterialProduct } from '@/types/ads';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Check, LoaderCircle, Plus, Save } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<AdsMaterialFormProps>();

const { t } = useI18n();
const toast = useToast();
const perms = useMaterialPermissions();

const editing = computed(() => props.material !== null);
const limits = computed(() => props.limits ?? { image_mb: 20, video_mb: 500 });

const m = props.material;
const form = useForm<{
    title: string;
    product_id: number | null;
    collection_ids: number[];
    types: string[];
    website_links: string;
    drive_links: string;
    ig_links: string;
    content_notes: string;
    media_buyer_id: number | null;
    files: File[];
    remove_file_ids: number[];
}>({
    title: m?.title ?? '',
    product_id: m?.product?.id ?? null,
    collection_ids: m?.collections.map((c) => c.id) ?? [],
    types: m ? [...m.types] : [],
    website_links: links(m?.website_links).join('\n'),
    drive_links: links(m?.drive_links).join('\n'),
    ig_links: links(m?.ig_links).join('\n'),
    content_notes: m?.content_notes ?? '',
    media_buyer_id: m?.buyer?.id ?? null,
    files: [],
    remove_file_ids: [],
});

const product = ref<MaterialProduct | null>(m?.product ?? null);
watch(product, (p) => (form.product_id = p?.id ?? null));

function toggleType(type: string): void {
    form.types = form.types.includes(type) ? form.types.filter((x) => x !== type) : [...form.types, type];
}

/** Field errors, including the per-item ones (`drive_links.1`, `files.0`). */
const errorsFor = (field: string): string[] =>
    Object.entries(form.errors as Record<string, string>)
        .filter(([k]) => k === field || k.startsWith(`${field}.`))
        .map(([, v]) => v);

const progress = computed(() => (form.progress ? Math.round(form.progress.percentage ?? 0) : null));

function submit(): void {
    const options = {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => toast.push(t('ads.materials.form.saved')),
        onError: () => toast.push(t('ads.materials.form.has_errors'), 'error'),
    };
    if (props.material) {
        // Multipart cannot be a real PUT from a browser: spoof the method.
        form.transform((d) => ({ ...d, _method: 'put' })).post(`/ads/materials/${props.material.id}`, options);
    } else {
        form.transform((d) => d).post('/ads/materials', options);
    }
}

/* ---- inline new collection ---- */
const newCollection = ref('');
const addingCollection = ref(false);
const collectionError = ref<string | null>(null);
function addCollection(): void {
    const name = newCollection.value.trim();
    if (!name) return;
    const before = new Set(props.collections.map((c) => c.id));
    addingCollection.value = true;
    collectionError.value = null;
    router.post(
        '/ads/collections',
        { name },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                const created = props.collections.find((c) => !before.has(c.id));
                if (created && !form.collection_ids.includes(created.id)) form.collection_ids = [...form.collection_ids, created.id];
                newCollection.value = '';
            },
            onError: (errors) => (collectionError.value = String(errors.name ?? Object.values(errors)[0] ?? t('common.error'))),
            onFinish: () => (addingCollection.value = false),
        },
    );
}

const inputClass =
    'flex h-9 w-full rounded-md border border-input bg-card px-3 text-sm placeholder:text-muted-foreground focus-visible:border-primary focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-primary disabled:opacity-50';
const areaClass =
    'flex min-h-20 w-full rounded-md border border-input bg-card px-3 py-2 text-sm placeholder:text-muted-foreground focus-visible:border-primary focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-primary';
const sectionClass = 'space-y-4 rounded-lg bg-card p-4 shadow-card md:p-5';

const pageTitle = computed(() => (editing.value ? t('ads.materials.form.edit_title') : t('ads.materials.form.create_title')));
const breadcrumbs = computed(() => [
    { title: t('nav.ads'), href: '/ads' },
    { title: t('nav.ads_materials'), href: '/ads/materials' },
    { title: pageTitle.value, href: props.material ? `/ads/materials/${props.material.id}/edit` : '/ads/materials/create' },
]);
</script>

<template>
    <Head :title="pageTitle" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form class="mx-auto w-full max-w-5xl space-y-4 p-3 md:p-6" novalidate @submit.prevent="submit">
            <PageHeader :title="pageTitle" :description="t('ads.materials.form.description')">
                <MaterialStatusChip v-if="material" :status="material.status" />
            </PageHeader>

            <!-- Basics -->
            <section :class="sectionClass" :aria-label="t('ads.materials.form.basics')">
                <div class="space-y-1">
                    <label class="text-xs font-medium" for="mat-title">{{ t('ads.materials.form.title') }}</label>
                    <input
                        id="mat-title"
                        v-model="form.title"
                        type="text"
                        maxlength="200"
                        required
                        :class="inputClass"
                        :placeholder="t('ads.materials.form.title_placeholder')"
                    />
                    <p v-if="form.errors.title" class="text-2xs text-destructive">{{ form.errors.title }}</p>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-medium" for="mat-product">{{ t('ads.materials.form.product') }}</label>
                    <ProductPicker v-model="product" input-id="mat-product" />
                    <p v-if="form.errors.product_id" class="text-2xs text-destructive">{{ form.errors.product_id }}</p>
                </div>

                <fieldset class="space-y-2">
                    <legend class="text-xs font-medium">{{ t('ads.materials.form.types') }}</legend>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="ty in types"
                            :key="ty"
                            type="button"
                            :aria-pressed="form.types.includes(ty)"
                            class="inline-flex h-8 items-center gap-1 rounded-full border px-3 text-xs font-medium transition-colors"
                            :class="
                                form.types.includes(ty)
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-border bg-background text-muted-foreground hover:text-foreground'
                            "
                            @click="toggleType(ty)"
                        >
                            <Check v-if="form.types.includes(ty)" class="size-3.5" aria-hidden="true" />{{ t(`ads.materials.type.${ty}`) }}
                        </button>
                    </div>
                    <p v-for="e in errorsFor('types')" :key="e" class="text-2xs text-destructive">{{ e }}</p>
                </fieldset>
            </section>

            <!-- Collections -->
            <section :class="sectionClass">
                <fieldset class="space-y-3">
                    <legend class="text-sm font-semibold">{{ t('ads.materials.form.collections') }}</legend>
                    <p v-if="!collections.length" class="text-xs text-muted-foreground">{{ t('ads.materials.form.no_collections') }}</p>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                        <label
                            v-for="c in collections"
                            :key="c.id"
                            class="flex cursor-pointer items-center gap-2 rounded-md border px-2.5 py-2 text-xs transition-colors"
                            :class="form.collection_ids.includes(c.id) ? 'border-primary bg-primary/5' : 'border-border hover:bg-muted'"
                        >
                            <input v-model="form.collection_ids" type="checkbox" :value="c.id" class="size-3.5 accent-primary" />
                            <span class="min-w-0 truncate" dir="auto">{{ c.name }}</span>
                        </label>
                    </div>
                    <p v-for="e in errorsFor('collection_ids')" :key="e" class="text-2xs text-destructive">{{ e }}</p>
                </fieldset>
                <div class="flex flex-wrap items-center gap-2">
                    <label class="sr-only" for="mat-new-collection">{{ t('ads.materials.form.new_collection') }}</label>
                    <input
                        id="mat-new-collection"
                        v-model="newCollection"
                        type="text"
                        maxlength="100"
                        :placeholder="t('ads.materials.form.new_collection')"
                        :class="cn(inputClass, 'h-8 max-w-60 text-xs')"
                        @keydown.enter.prevent="addCollection"
                    />
                    <button
                        type="button"
                        :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'gap-1')"
                        :disabled="addingCollection || !newCollection.trim()"
                        @click="addCollection"
                    >
                        <LoaderCircle v-if="addingCollection" class="size-3.5 animate-spin" aria-hidden="true" />
                        <Plus v-else class="size-3.5" aria-hidden="true" />{{ t('ads.materials.form.add_collection') }}
                    </button>
                    <p v-if="collectionError" role="alert" class="w-full text-2xs text-destructive">{{ collectionError }}</p>
                </div>
            </section>

            <!-- Files -->
            <section :class="sectionClass">
                <h2 class="text-sm font-semibold">{{ t('ads.materials.form.files') }}</h2>
                <MaterialFileDrop
                    v-model="form.files"
                    v-model:removed="form.remove_file_ids"
                    :existing="material?.files ?? []"
                    :limits="limits"
                    :progress="progress"
                    :disabled="form.processing"
                />
                <p v-for="e in errorsFor('files')" :key="e" class="text-2xs text-destructive">{{ e }}</p>
            </section>

            <!-- Links + notes -->
            <section :class="sectionClass">
                <h2 class="text-sm font-semibold">{{ t('ads.materials.form.links') }}</h2>
                <p class="text-2xs text-muted-foreground">{{ t('ads.materials.form.links_hint') }}</p>
                <div class="grid gap-4 md:grid-cols-3">
                    <div v-for="field in ['website_links', 'drive_links', 'ig_links'] as const" :key="field" class="space-y-1">
                        <label class="text-xs font-medium" :for="`mat-${field}`">{{ t(`ads.materials.form.${field}`) }}</label>
                        <textarea :id="`mat-${field}`" v-model="form[field]" dir="ltr" rows="3" :class="areaClass" placeholder="https://" />
                        <p v-for="e in errorsFor(field)" :key="e" class="text-2xs text-destructive">{{ e }}</p>
                    </div>
                </div>
                <div class="space-y-1">
                    <label class="text-xs font-medium" for="mat-notes">{{ t('ads.materials.form.content_notes') }}</label>
                    <textarea
                        id="mat-notes"
                        v-model="form.content_notes"
                        rows="4"
                        maxlength="5000"
                        :class="areaClass"
                        :placeholder="t('ads.materials.form.content_notes_placeholder')"
                    />
                    <p v-if="form.errors.content_notes" class="text-2xs text-destructive">{{ form.errors.content_notes }}</p>
                </div>
                <div class="max-w-xs space-y-1">
                    <label class="text-xs font-medium" for="mat-buyer">{{ t('ads.materials.form.buyer') }}</label>
                    <select id="mat-buyer" v-model="form.media_buyer_id" :class="inputClass">
                        <option :value="null">{{ t('ads.materials.form.no_buyer') }}</option>
                        <option v-for="b in buyers" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                    <p v-if="form.errors.media_buyer_id" class="text-2xs text-destructive">{{ form.errors.media_buyer_id }}</p>
                </div>
            </section>

            <!-- Linked ads: buyers and supervisors only; saved on its own -->
            <section v-if="perms.canOperate.value" :class="sectionClass">
                <h2 class="text-sm font-semibold">{{ t('ads.materials.link.title') }}</h2>
                <AdLinkPicker v-if="material" :material-id="material.id" :ads="material.ads" />
                <p v-else class="text-xs text-muted-foreground">{{ t('ads.materials.form.link_after_save') }}</p>
            </section>

            <div
                class="sticky bottom-0 z-10 -mx-3 flex flex-wrap items-center gap-2 border-t border-border bg-background/95 px-3 py-3 backdrop-blur md:static md:mx-0 md:border-0 md:bg-transparent md:p-0"
            >
                <button type="submit" :class="cn(buttonVariants({ variant: 'default' }), 'gap-1.5')" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" aria-hidden="true" />
                    <Save v-else class="size-4" aria-hidden="true" />
                    {{ form.processing && progress !== null ? t('ads.materials.form.uploading', { n: progress }) : t('ads.materials.form.submit') }}
                </button>
                <Link href="/ads/materials" :class="buttonVariants({ variant: 'outline' })">{{ t('common.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
