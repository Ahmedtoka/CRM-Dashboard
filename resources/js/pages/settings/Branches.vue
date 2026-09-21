<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BranchRow } from '@/types/admin';
import { Head, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{ branches: BranchRow[] }>();

const { t, locale, dir } = useI18n();
const toast = useToast();
const api = useApi();

// The branch route is PATCH (a partial update, e.g. flipping just `is_active`),
// unlike the PUT-based `useCrud` composable other settings pages use.
const busy = ref(false);
const error = ref<string | null>(null);

function reload(): void {
    router.reload({ only: ['branches'] });
}

async function save(id: number | null, payload: Record<string, unknown>): Promise<boolean> {
    busy.value = true;
    error.value = null;
    try {
        if (id === null) await api.post('/settings/branches', payload);
        else await api.patch(`/settings/branches/${id}`, payload);
        toast.push(t('ui.saved'));
        reload();
        return true;
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
        return false;
    } finally {
        busy.value = false;
    }
}

async function remove(id: number, confirmText: string): Promise<void> {
    if (!window.confirm(confirmText)) return;
    try {
        await api.delete(`/settings/branches/${id}`);
        toast.push(t('ui.deleted'));
        reload();
    } catch (e) {
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    }
}

/**
 * BranchFinder's known area keys (Task 2 brief) with their canonical Arabic
 * and English labels. Picking an area auto-fills area_ar/area_en so a new
 * branch always carries labels BranchFinder recognizes.
 */
const AREA_OPTIONS: { key: string; ar: string; en: string }[] = [
    { key: 'heliopolis', ar: 'مصر الجديدة', en: 'Heliopolis' },
    { key: 'nasr_city', ar: 'مدينة نصر', en: 'Nasr City' },
    { key: 'fifth_settlement', ar: 'التجمع الخامس', en: '5th Settlement' },
    { key: 'rehab', ar: 'الرحاب', en: 'El Rehab' },
    { key: 'madinaty', ar: 'مدينتي', en: 'Madinaty' },
    { key: 'mokattam', ar: 'المقطم', en: 'El Mokkatam' },
    { key: 'maadi', ar: 'المعادي', en: 'Maadi' },
    { key: 'mohandessin', ar: 'المهندسين', en: 'El Mohandessin' },
    { key: 'october_zayed', ar: 'أكتوبر والشيخ زايد', en: '6th of October & Sheikh Zayed' },
    { key: 'alexandria', ar: 'الإسكندرية', en: 'Alexandria' },
    { key: 'mansoura', ar: 'المنصورة', en: 'Mansoura' },
    { key: 'zagazig', ar: 'الزقازيق', en: 'Zagazig' },
];

const open = ref(false);
const editingId = ref<number | null>(null);
const form = reactive({
    governorate: '',
    area_key: AREA_OPTIONS[0].key,
    area_ar: AREA_OPTIONS[0].ar,
    area_en: AREA_OPTIONS[0].en,
    name: '',
    address: '',
    phone: '',
    map_url: '',
    hours: '',
    aliases: '',
    is_active: true,
    sort: 0,
});

function onAreaChange(): void {
    const area = AREA_OPTIONS.find((a) => a.key === form.area_key);
    if (area) {
        form.area_ar = area.ar;
        form.area_en = area.en;
    }
}

function edit(row: BranchRow | null): void {
    editingId.value = row?.id ?? null;
    Object.assign(form, {
        governorate: row?.governorate ?? '',
        area_key: row?.area_key ?? AREA_OPTIONS[0].key,
        area_ar: row?.area_ar ?? AREA_OPTIONS[0].ar,
        area_en: row?.area_en ?? AREA_OPTIONS[0].en,
        name: row?.name ?? '',
        address: row?.address ?? '',
        phone: row?.phone ?? '',
        map_url: row?.map_url ?? '',
        hours: row?.hours ?? '',
        aliases: (row?.aliases ?? []).join(', '),
        is_active: row?.is_active ?? true,
        sort: row?.sort ?? 0,
    });
    error.value = null;
    open.value = true;
}

async function submit(): Promise<void> {
    const payload = {
        ...form,
        aliases: form.aliases
            .split(',')
            .map((a) => a.trim())
            .filter((a) => a !== ''),
    };

    if (await save(editingId.value, payload)) open.value = false;
}

function toggleActive(row: BranchRow): void {
    save(row.id, { is_active: !row.is_active });
}

/** Branches grouped by area_key, preserving the backend's `sort` order. */
const groups = computed(() => {
    const map = new Map<string, { key: string; label: string; rows: BranchRow[] }>();

    for (const row of props.branches) {
        if (!map.has(row.area_key)) {
            map.set(row.area_key, { key: row.area_key, label: (locale.value === 'en' ? row.area_en : row.area_ar) || row.area_ar, rows: [] });
        }
        map.get(row.area_key)!.rows.push(row);
    }

    return Array.from(map.values());
});

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('settings.branches.name') },
    { key: 'address', label: t('settings.branches.address') },
    { key: 'phone', label: t('settings.branches.phone') },
    { key: 'is_active', label: t('settings.branches.active'), align: 'center' },
    { key: 'actions', label: t('ui.actions'), align: 'end' },
]);

const breadcrumbs = computed(() => [{ title: t('settings.branches.title'), href: '/settings/branches' }]);
const input = 'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';
const textarea = 'w-full rounded-md border border-input bg-background px-3 py-2 text-sm';
const iconBtn = 'rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground';
</script>

<template>
    <Head :title="t('settings.branches.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-4xl space-y-6 p-3 md:p-6">
            <PageHeader :title="t('settings.branches.title')">
                <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground" @click="edit(null)">
                    <Plus class="size-3.5" aria-hidden="true" />{{ t('settings.branches.add') }}
                </button>
            </PageHeader>

            <p v-if="!branches.length" class="rounded-lg bg-card p-6 text-center text-sm text-muted-foreground shadow-card">
                {{ t('settings.branches.empty') }}
            </p>

            <div v-for="group in groups" :key="group.key" class="space-y-2">
                <h2 class="flex items-center gap-2 text-sm font-semibold" :dir="dir">
                    {{ group.label }}
                    <span class="text-xs font-normal text-muted-foreground">{{ t('settings.branches.count', { count: group.rows.length }) }}</span>
                </h2>

                <DataTable :columns="columns" :rows="group.rows" :empty="t('settings.branches.empty')" :caption="group.label">
                    <template #cell-name="{ row }"><span class="font-medium" dir="rtl">{{ row.name }}</span></template>
                    <template #cell-address="{ row }"><span class="text-xs" dir="rtl">{{ row.address }}</span></template>
                    <template #cell-phone="{ row }"><span dir="ltr">{{ row.phone ?? '—' }}</span></template>
                    <template #cell-is_active="{ row }">
                        <button
                            type="button"
                            class="rounded-full px-2 py-0.5 text-2xs font-medium"
                            :class="row.is_active ? 'bg-emerald-500/10 text-emerald-600' : 'bg-muted text-muted-foreground'"
                            @click="toggleActive(row)"
                        >
                            {{ row.is_active ? t('settings.branches.active') : t('settings.branches.inactive') }}
                        </button>
                    </template>
                    <template #cell-actions="{ row }">
                        <span class="inline-flex gap-0.5">
                            <button type="button" :class="iconBtn" :title="t('ui.edit')" :aria-label="`${t('ui.edit')} ${row.name}`" @click="edit(row)"><Pencil class="size-3.5" /></button>
                            <button
                                type="button"
                                :class="[iconBtn, 'hover:text-destructive']"
                                :title="t('ui.delete')"
                                :aria-label="`${t('ui.delete')} ${row.name}`"
                                @click="remove(row.id, t('ui.confirm_delete', { name: row.name }))"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </span>
                    </template>
                </DataTable>
            </div>
        </div>

        <FormDialog v-model:open="open" :title="editingId ? t('settings.branches.edit') : t('settings.branches.add')" :busy="busy" :error="error" wide @submit="submit">
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.branches.area') }}</span>
                <select v-model="form.area_key" required :class="input" :dir="locale === 'en' ? 'ltr' : 'rtl'" @change="onAreaChange">
                    <option v-for="area in AREA_OPTIONS" :key="area.key" :value="area.key">{{ locale === 'en' ? area.en : area.ar }}</option>
                </select>
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.branches.governorate') }}</span>
                <input v-model="form.governorate" dir="rtl" required maxlength="60" :class="input" />
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.branches.name') }}</span>
                <input v-model="form.name" dir="rtl" required maxlength="120" :class="input" />
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.branches.address') }}</span>
                <textarea v-model="form.address" dir="rtl" required maxlength="500" rows="2" :class="textarea" />
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.branches.phone') }}</span>
                <input v-model="form.phone" dir="ltr" maxlength="30" :class="input" />
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.branches.map_url') }}</span>
                <input v-model="form.map_url" type="url" dir="ltr" maxlength="500" :class="input" />
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.branches.hours') }}</span>
                <input v-model="form.hours" dir="rtl" maxlength="200" :class="input" />
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.branches.aliases') }}</span>
                <textarea v-model="form.aliases" dir="rtl" rows="2" :class="textarea" />
            </label>
            <label class="flex items-center gap-2">
                <input v-model="form.is_active" type="checkbox" class="size-4 rounded border-input" />
                <span class="text-sm font-semibold">{{ t('settings.branches.active') }}</span>
            </label>
        </FormDialog>
    </AppLayout>
</template>
