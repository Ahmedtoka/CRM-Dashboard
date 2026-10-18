<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import EmptyState from '@/components/crm/EmptyState.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import IconAction from '@/components/crm/IconAction.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import UserForm from '@/components/crm/UserForm.vue';
import SettingsShell from '@/components/crm/settings/SettingsShell.vue';
import { Button } from '@/components/ui/button';
import { useCrud } from '@/composables/useCrud';
import { useI18n } from '@/composables/useI18n';
import { getInitials } from '@/composables/useInitials';
import { useSettingsBreadcrumbs } from '@/composables/useSettingsBreadcrumbs';
import { useUrlFilters } from '@/composables/useUrlFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import { sortRows } from '@/lib/sort';
import type { ManagedUser } from '@/types/admin';
import type { Role } from '@/types/crm';
import { Head } from '@inertiajs/vue3';
import { KeyRound, Pencil, Plus, Users as UsersIcon, UserX } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ users: ManagedUser[]; roles: Role[] }>();

const { t, locale } = useI18n();

// A full list: it sorts in the browser, and the sort stays in the URL.
const { filters, set } = useUrlFilters({ sort: '' });
// Roles sort by the label people read, not by the stored key.
const shown = computed(() =>
    sortRows(props.users, filters.value.sort, (row, key) => (key === 'role' ? t(`roles.${row.role}`) : row[key as keyof ManagedUser])),
);

const crud = useCrud('/settings/users', 'users');

const formOpen = ref(false);
const editing = ref<ManagedUser | null>(null);
const resetFor = ref<ManagedUser | null>(null);
const newPassword = ref('');

function openForm(user: ManagedUser | null): void {
    editing.value = user;
    crud.error.value = null;
    formOpen.value = true;
}

async function submit(id: number | null, payload: Record<string, unknown>): Promise<void> {
    if (await crud.save(id, payload)) formOpen.value = false;
}

function openReset(user: ManagedUser): void {
    resetFor.value = user;
    newPassword.value = '';
    crud.error.value = null;
}

// The update endpoint validates the whole user, so the reset resends the current fields.
async function submitReset(): Promise<void> {
    const u = resetFor.value;
    if (!u) return;
    const payload = {
        name: u.name,
        email: u.email,
        role: u.role,
        locale: u.locale,
        color: u.color,
        is_active: u.is_active,
        password: newPassword.value,
    };
    if (await crud.save(u.id, payload, 'settings.users.reset_done')) resetFor.value = null;
}

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('settings.users.name'), sortable: true, primary: true },
    { key: 'role', label: t('settings.users.role'), sortable: true },
    { key: 'platforms', label: t('settings.users.platforms') },
    { key: 'is_active', label: t('settings.users.status') },
    { key: 'last_seen_at', label: t('settings.users.last_seen'), sortable: true, hideOnMobile: true },
    { key: 'actions', label: t('ui.actions'), align: 'end' },
]);

const title = computed(() => t('settings_hub.items.users'));
const breadcrumbs = useSettingsBreadcrumbs(() => title.value);
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsShell :title="title" :description="t('settings.users.description')" width="wide">
            <template #actions>
                <Button @click="openForm(null)"><Plus aria-hidden="true" />{{ t('settings.users.add') }}</Button>
            </template>

            <DataTable
                table-id="settings-users"
                :columns="columns"
                :rows="shown"
                :caption="title"
                :sort="filters.sort || null"
                :row-class="(r) => (r.is_active ? undefined : 'opacity-70')"
                @update:sort="set({ sort: $event })"
            >
                <template #empty>
                    <EmptyState :icon="UsersIcon" :title="t('settings.users.empty')" :body="t('settings.users.empty_body')">
                        <template #action>
                            <Button size="sm" @click="openForm(null)"><Plus aria-hidden="true" />{{ t('settings.users.add') }}</Button>
                        </template>
                    </EmptyState>
                </template>
                <template #cell-name="{ row }">
                    <span class="flex min-w-0 items-center gap-2.5">
                        <span
                            class="text-12 grid size-8 shrink-0 place-items-center rounded-full font-bold text-white"
                            :class="row.color ? '' : 'bg-brand'"
                            :style="row.color ? { backgroundColor: row.color } : undefined"
                            aria-hidden="true"
                            >{{ getInitials(row.name) }}</span
                        >
                        <span class="min-w-0">
                            <span class="block truncate font-semibold" dir="auto">{{ row.name }}</span>
                            <bdi class="text-12 block truncate text-fg-3" dir="ltr">{{ row.email }}</bdi>
                        </span>
                    </span>
                </template>
                <template #cell-role="{ row }">{{ t(`roles.${row.role}`) }}</template>
                <template #cell-platforms="{ row }">
                    <span v-if="row.role !== 'moderator'" class="text-fg-2">{{ t('ui.all_platforms') }}</span>
                    <span v-else-if="row.platforms.length" class="flex flex-wrap gap-1"
                        ><PlatformBadge v-for="p in row.platforms" :key="p" :platform="p" size="xs"
                    /></span>
                    <span v-else class="text-fg-3">—</span>
                </template>
                <template #cell-is_active="{ row }">
                    <StatusChip :label="row.is_active ? t('ui.active') : t('ui.inactive')" :tone="row.is_active ? 'ok' : 'neutral'" />
                </template>
                <template #cell-last_seen_at="{ row }">
                    <span class="whitespace-nowrap tabular-nums text-fg-2">{{ formatDateTime(row.last_seen_at, locale) || t('ui.never') }}</span>
                </template>
                <template #cell-actions="{ row }">
                    <span class="inline-flex gap-0.5">
                        <IconAction :icon="Pencil" size="sm" :label="`${t('ui.edit')} ${row.name}`" @click="openForm(row)" />
                        <IconAction :icon="KeyRound" size="sm" :label="`${t('settings.users.reset_password')} ${row.name}`" @click="openReset(row)" />
                        <IconAction
                            v-if="row.is_active"
                            :icon="UserX"
                            size="sm"
                            variant="destructive"
                            :label="`${t('settings.users.deactivate')} ${row.name}`"
                            @click="crud.remove(row.id, t('settings.users.deactivate_confirm', { name: row.name }), 'settings.users.deactivated')"
                        />
                    </span>
                </template>
            </DataTable>
        </SettingsShell>

        <UserForm v-model:open="formOpen" :user="editing" :roles="roles" :busy="crud.busy.value" :error="crud.error.value" @submit="submit" />

        <FormDialog
            :open="resetFor !== null"
            :title="t('settings.users.reset_password')"
            :description="resetFor?.name"
            :busy="crud.busy.value"
            :error="crud.error.value"
            @update:open="!$event && (resetFor = null)"
            @submit="submitReset"
        >
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.users.new_password') }}</span>
                <input
                    v-model="newPassword"
                    type="password"
                    dir="ltr"
                    required
                    minlength="8"
                    autocomplete="new-password"
                    class="h-10 w-full rounded-md border border-line-strong bg-card px-3 text-sm focus-visible:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40"
                />
            </label>
        </FormDialog>
    </AppLayout>
</template>
