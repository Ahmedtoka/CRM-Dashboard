<script setup lang="ts">
import SectionTabs from '@/components/crm/SectionTabs.vue';
import { sectionTabs } from '@/lib/sectionTabs';
/** Ads Hub — مجموعات المواد: collections with their material counts; content and supervisors manage them (spec §8.7). */
import EmptyState from '@/components/crm/EmptyState.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import IconAction from '@/components/crm/IconAction.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { buttonVariants } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { useMaterialPermissions } from '@/lib/adsMaterials';
import { formatCount } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { AdsMaterialCollectionsProps, MaterialCollectionRow } from '@/types/ads';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CircleCheckBig, CirclePlay, Eye, FolderOpen, Hourglass, Layers, OctagonAlert, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, type Component } from 'vue';

defineProps<AdsMaterialCollectionsProps>();

const { t, locale } = useI18n();
const toast = useToast();
const perms = useMaterialPermissions();
const n = (v: number) => formatCount(v, locale.value);

interface Tile {
    key: keyof AdsMaterialCollectionsProps['totals'];
    icon: Component;
    border: string;
    tint: string;
}
const tiles: Tile[] = [
    { key: 'collections', icon: FolderOpen, border: 'border-t-primary', tint: 'text-brand-ink' },
    { key: 'materials', icon: Layers, border: 'border-t-violet-500', tint: 'text-brand-ink' },
    { key: 'activated', icon: CirclePlay, border: 'border-t-success', tint: 'text-ok' },
    { key: 'not_started', icon: Hourglass, border: 'border-t-warning', tint: 'text-warn' },
    { key: 'done', icon: CircleCheckBig, border: 'border-t-info', tint: 'text-info' },
    { key: 'need_stop', icon: OctagonAlert, border: 'border-t-destructive', tint: 'text-danger' },
];

const pills: { key: 'activated' | 'not_started' | 'need_stop' | 'done'; cls: string }[] = [
    { key: 'activated', cls: 'bg-ok-soft text-ok' },
    { key: 'not_started', cls: 'bg-warn-soft text-warn' },
    { key: 'need_stop', cls: 'bg-danger-soft text-danger' },
    { key: 'done', cls: 'bg-info-soft text-info' },
];

/* ---- add / edit ---- */
const dialogOpen = ref(false);
const editing = ref<MaterialCollectionRow | null>(null);
const form = useForm<{ name: string; is_active: boolean }>({ name: '', is_active: true });

function openAdd(): void {
    editing.value = null;
    form.defaults({ name: '', is_active: true }).reset();
    form.clearErrors();
    dialogOpen.value = true;
}
function openEdit(c: MaterialCollectionRow): void {
    editing.value = c;
    form.defaults({ name: c.name, is_active: c.is_active }).reset();
    form.clearErrors();
    dialogOpen.value = true;
}
function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
            toast.push(t('ads.materials.collections.saved'));
        },
    };
    if (editing.value) form.put(`/ads/collections/${editing.value.id}`, options);
    else form.post('/ads/collections', options);
}

/* ---- delete ---- */
const deleting = ref<MaterialCollectionRow | null>(null);
const deleteOpen = computed({ get: () => deleting.value !== null, set: (v) => !v && (deleting.value = null) });
const deleteBusy = ref(false);
function confirmDelete(): void {
    const c = deleting.value;
    if (!c) return;
    deleteBusy.value = true;
    router.delete(`/ads/collections/${c.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleting.value = null;
            toast.push(t('ads.materials.collections.deleted'));
        },
        onError: (errors) => toast.push(String(Object.values(errors)[0] ?? t('common.error')), 'error'),
        onFinish: () => (deleteBusy.value = false),
    });
}

const breadcrumbs = computed(() => [
    { title: t('nav.ads'), href: '/ads/materials' },
    { title: t('nav.ads_collections'), href: '/ads/collections' },
]);
</script>

<template>
    <Head :title="t('ads.materials.collections.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full min-w-0 max-w-[1400px] space-y-5 p-4 md:p-6">
            <PageHeader :title="t('ads.materials.collections.title')" :description="t('ads.materials.collections.description')">
                <button
                    v-if="perms.canAuthor.value"
                    type="button"
                    :class="cn(buttonVariants({ variant: 'default', size: 'sm' }), 'gap-1.5')"
                    @click="openAdd"
                >
                    <Plus aria-hidden="true" />{{ t('ads.materials.collections.add') }}
                </button>
            </PageHeader>
            <SectionTabs :tabs="sectionTabs.library(t)" :label="t('section_tabs.library.label')" />

            <ul class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-line bg-line sm:grid-cols-3 lg:grid-cols-6">
                <li v-for="tile in tiles" :key="tile.key" class="min-w-0 bg-card px-3.5 py-3">
                    <p class="flex items-center gap-1.5 text-[12.5px] font-medium text-fg-2">
                        <component :is="tile.icon" class="size-3.5 shrink-0" :class="tile.tint" aria-hidden="true" />
                        <span class="truncate">{{ t(`ads.materials.collections.kpi.${tile.key}`) }}</span>
                    </p>
                    <p class="mt-0.5 text-xl font-bold leading-snug tabular-nums" :class="tile.key === 'need_stop' && totals.need_stop > 0 ? 'text-danger' : ''">
                        {{ n(totals[tile.key]) }}
                    </p>
                </li>
            </ul>

            <EmptyState
                v-if="!collections.length"
                :icon="FolderOpen"
                :title="t('ads.materials.collections.empty')"
                :body="perms.canAuthor.value ? t('ads.materials.collections.empty_body') : undefined"
            />
            <!-- One card per collection: three of its newest pictures, the name, the counts, the actions -->
            <ul v-else class="grid grid-cols-2 gap-3.5 sm:grid-cols-[repeat(auto-fill,minmax(220px,1fr))]" data-test="collection-cards">
                <li v-for="c in collections" :key="c.id" class="flex min-w-0 flex-col overflow-hidden rounded-lg border border-line bg-card">
                    <Link
                        :href="`/ads/materials?collection=${c.id}`"
                        class="grid aspect-[4/3] grid-cols-[2fr_1fr] grid-rows-2 gap-0.5 bg-line focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                        :aria-label="t('ads.materials.collections.view', { name: c.name })"
                    >
                        <span
                            v-for="i in 3"
                            :key="i"
                            class="block overflow-hidden bg-sunken"
                            :class="i === 1 ? 'row-span-2' : ''"
                        >
                            <img v-if="c.covers?.[i - 1]" :src="c.covers[i - 1]" alt="" loading="lazy" class="size-full object-cover" />
                            <FolderOpen v-else-if="i === 1" class="m-auto mt-[38%] size-6 text-fg-3" aria-hidden="true" />
                        </span>
                    </Link>
                    <div class="flex flex-1 flex-col gap-1.5 px-3 pb-2 pt-2.5">
                        <p class="flex min-w-0 items-center gap-2">
                            <span class="truncate text-[14px] font-semibold" dir="auto">{{ c.name }}</span>
                            <StatusChip v-if="!c.is_active" :label="t('ads.materials.collections.inactive')" size="sm" />
                        </p>
                        <p class="text-xs text-fg-2">{{ t('ads.materials.collections.materials_n', { n: n(c.materials) }) }}</p>
                        <p class="flex flex-wrap gap-1">
                            <template v-for="p in pills" :key="p.key">
                                <span
                                    v-if="c[p.key] > 0"
                                    class="inline-flex h-6 items-center gap-1 rounded-full px-2 text-xs font-semibold tabular-nums"
                                    :class="p.cls"
                                    >{{ t(`ads.materials.collections.kpi.${p.key}`) }} {{ n(c[p.key]) }}</span
                                >
                            </template>
                        </p>
                    </div>
                    <div class="flex justify-end gap-0.5 border-t border-line px-1.5 py-1">
                        <IconAction :icon="Eye" variant="primary" :href="`/ads/materials?collection=${c.id}`" :label="t('ads.materials.collections.view', { name: c.name })" />
                        <template v-if="perms.canAuthor.value">
                            <IconAction :icon="Pencil" :label="t('ads.materials.actions.edit')" @click="openEdit(c)" />
                            <IconAction :icon="Trash2" variant="destructive" :label="t('ads.materials.actions.delete')" @click="deleting = c" />
                        </template>
                    </div>
                </li>
            </ul>
        </div>

        <FormDialog
            v-model:open="dialogOpen"
            :title="editing ? t('ads.materials.collections.edit_title') : t('ads.materials.collections.add')"
            :busy="form.processing"
            @submit="submit"
        >
            <div class="space-y-1">
                <label class="text-xs font-medium" for="col-name">{{ t('ads.materials.collections.name') }}</label>
                <input
                    id="col-name"
                    v-model="form.name"
                    type="text"
                    maxlength="100"
                    required
                    class="flex h-10 w-full rounded-md border border-line-strong bg-card px-3 text-sm focus-visible:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
                <p v-if="form.errors.name" class="text-2xs text-destructive">{{ form.errors.name }}</p>
            </div>
            <label class="flex items-center gap-2 text-xs">
                <input v-model="form.is_active" type="checkbox" class="size-4 accent-primary" />
                {{ t('ads.materials.collections.active') }}
            </label>
        </FormDialog>

        <FormDialog
            v-model:open="deleteOpen"
            :title="t('ads.materials.collections.delete_title')"
            :description="deleting ? t('ads.materials.collections.delete_body', { name: deleting.name }) : undefined"
            :submit-label="t('ads.materials.actions.delete')"
            :busy="deleteBusy"
            destructive
            @submit="confirmDelete"
        />
    </AppLayout>
</template>
