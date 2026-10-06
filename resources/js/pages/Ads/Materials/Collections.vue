<script setup lang="ts">
/** Ads Hub — مجموعات المواد: collections with their material counts; content and supervisors manage them (spec §8.7). */
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
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
import { Head, router, useForm } from '@inertiajs/vue3';
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
    { key: 'collections', icon: FolderOpen, border: 'border-t-primary', tint: 'bg-primary/10 text-primary' },
    { key: 'materials', icon: Layers, border: 'border-t-violet-500', tint: 'bg-violet-500/10 text-violet-700 dark:text-violet-300' },
    { key: 'activated', icon: CirclePlay, border: 'border-t-success', tint: 'bg-success/15 text-emerald-700 dark:text-emerald-300' },
    { key: 'not_started', icon: Hourglass, border: 'border-t-warning', tint: 'bg-warning/20 text-amber-800 dark:text-amber-200' },
    { key: 'done', icon: CircleCheckBig, border: 'border-t-info', tint: 'bg-info/10 text-blue-700 dark:text-blue-200' },
    { key: 'need_stop', icon: OctagonAlert, border: 'border-t-destructive', tint: 'bg-destructive/10 text-destructive' },
];

const pills: { key: 'activated' | 'not_started' | 'need_stop' | 'done'; cls: string }[] = [
    { key: 'activated', cls: 'bg-success/15 text-emerald-800 dark:bg-success/25 dark:text-emerald-200' },
    { key: 'not_started', cls: 'bg-warning/20 text-amber-900 dark:bg-warning/25 dark:text-amber-100' },
    { key: 'need_stop', cls: 'bg-destructive/10 text-destructive dark:bg-destructive/25 dark:text-red-200' },
    { key: 'done', cls: 'bg-info/10 text-blue-800 dark:bg-info/25 dark:text-blue-100' },
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

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('ads.materials.collections.col.name'), primary: true },
    { key: 'materials', label: t('ads.materials.collections.col.materials'), align: 'center', numeric: true },
    ...pills.map((p) => ({ key: p.key, label: t(`ads.materials.collections.col.${p.key}`), align: 'center' as const, numeric: true })),
    { key: 'actions', label: t('ads.materials.col.actions'), align: 'end' },
]);
const breadcrumbs = computed(() => [
    { title: t('nav.ads'), href: '/ads' },
    { title: t('nav.ads_collections'), href: '/ads/collections' },
]);
</script>

<template>
    <Head :title="t('ads.materials.collections.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-4 p-3 md:p-6">
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

            <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <li v-for="tile in tiles" :key="tile.key" class="rounded-lg border-t-4 bg-card px-3 py-3 shadow-card" :class="tile.border">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-2xs font-medium text-muted-foreground">{{ t(`ads.materials.collections.kpi.${tile.key}`) }}</p>
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-md" :class="tile.tint">
                            <component :is="tile.icon" class="size-3.5" aria-hidden="true" />
                        </span>
                    </div>
                    <p class="mt-1 text-xl font-bold tabular-nums">{{ n(totals[tile.key]) }}</p>
                </li>
            </ul>

            <DataTable
                table-id="ads-collections"
                :columns="columns"
                :rows="collections"
                mobile="scroll"
                :caption="t('ads.materials.collections.title')"
            >
                <template #empty>
                    <EmptyState
                        :icon="FolderOpen"
                        :title="t('ads.materials.collections.empty')"
                        :body="perms.canAuthor.value ? t('ads.materials.collections.empty_body') : undefined"
                    />
                </template>
                <template #cell-name="{ row }">
                    <div class="flex items-center gap-2">
                        <span class="font-semibold" dir="auto">{{ row.name }}</span>
                        <StatusChip v-if="!row.is_active" :label="t('ads.materials.collections.inactive')" />
                    </div>
                </template>
                <template #cell-materials="{ row }"
                    ><span class="font-semibold tabular-nums">{{ n(row.materials) }}</span></template
                >
                <template v-for="p in pills" :key="p.key" #[`cell-${p.key}`]="{ row }">
                    <span
                        class="inline-flex h-6 min-w-8 items-center justify-center rounded-full px-2 text-2xs font-semibold tabular-nums"
                        :class="row[p.key] > 0 ? p.cls : 'bg-muted text-muted-foreground'"
                        >{{ n(row[p.key]) }}</span
                    >
                </template>
                <template #cell-actions="{ row: c }">
                    <div class="flex justify-end gap-0.5">
                        <IconAction :icon="Eye" variant="primary" :href="`/ads/materials?collection=${c.id}`" :label="t('ads.materials.collections.view', { name: c.name })" />
                        <template v-if="perms.canAuthor.value">
                            <IconAction :icon="Pencil" :label="t('ads.materials.actions.edit')" @click="openEdit(c)" />
                            <IconAction :icon="Trash2" variant="destructive" :label="t('ads.materials.actions.delete')" @click="deleting = c" />
                        </template>
                    </div>
                </template>
            </DataTable>
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
                    class="flex h-9 w-full rounded-md border border-input bg-card px-3 text-sm focus-visible:border-primary focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-primary"
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
