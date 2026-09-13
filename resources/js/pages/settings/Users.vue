<script setup lang="ts">
import DataTable, { type Column } from '@/components/crm/DataTable.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import PlatformBadge from '@/components/crm/PlatformBadge.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import UserForm from '@/components/crm/UserForm.vue';
import { useCrud } from '@/composables/useCrud';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import type { ManagedUser } from '@/types/admin';
import type { Role } from '@/types/crm';
import { Head } from '@inertiajs/vue3';
import { KeyRound, Pencil, Plus, UserX } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineProps<{ users: ManagedUser[]; roles: Role[] }>();

const { t, locale } = useI18n();
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
    const payload = { name: u.name, email: u.email, role: u.role, locale: u.locale, color: u.color, is_active: u.is_active, password: newPassword.value };
    if (await crud.save(u.id, payload, 'settings.users.reset_done')) resetFor.value = null;
}

const columns = computed<Column[]>(() => [
    { key: 'name', label: t('settings.users.name') },
    { key: 'role', label: t('settings.users.role') },
    { key: 'platforms', label: t('settings.users.platforms') },
    { key: 'is_active', label: t('ui.active') },
    { key: 'last_seen_at', label: t('settings.users.last_seen') },
    { key: 'actions', label: t('ui.actions'), align: 'end' },
]);

const breadcrumbs = computed(() => [{ title: t('settings.users.title'), href: '/settings/users' }]);
const iconBtn = 'rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground';
</script>

<template>
    <Head :title="t('settings.users.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <PageHeader :title="t('settings.users.title')">
                <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground" @click="openForm(null)">
                    <Plus class="size-3.5" aria-hidden="true" />{{ t('settings.users.add') }}
                </button>
            </PageHeader>

            <DataTable :columns="columns" :rows="users" :caption="t('settings.users.title')">
                <template #cell-name="{ row }">
                    <span class="flex items-center gap-2">
                        <span class="size-2.5 shrink-0 rounded-full" :style="{ backgroundColor: row.color ?? '#94a3b8' }" aria-hidden="true" />
                        <span>
                            <span class="block font-medium">{{ row.name }}</span>
                            <span class="block text-2xs text-muted-foreground" dir="ltr">{{ row.email }}</span>
                        </span>
                    </span>
                </template>
                <template #cell-role="{ row }">{{ t(`roles.${row.role}`) }}</template>
                <template #cell-platforms="{ row }">
                    <span v-if="row.role !== 'moderator'" class="text-muted-foreground">{{ t('ui.all_platforms') }}</span>
                    <span v-else class="flex gap-1"><PlatformBadge v-for="p in row.platforms" :key="p" :platform="p" size="xs" /></span>
                </template>
                <template #cell-is_active="{ row }">
                    <StatusChip :label="row.is_active ? t('ui.active') : t('ui.inactive')" :tone="row.is_active ? 'positive' : 'neutral'" />
                </template>
                <template #cell-last_seen_at="{ row }">
                    <span class="whitespace-nowrap tabular-nums text-muted-foreground">{{ formatDateTime(row.last_seen_at, locale) || t('ui.never') }}</span>
                </template>
                <template #cell-actions="{ row }">
                    <span class="inline-flex gap-0.5">
                        <button type="button" :class="iconBtn" :title="t('ui.edit')" :aria-label="`${t('ui.edit')} ${row.name}`" @click="openForm(row)"><Pencil class="size-3.5" /></button>
                        <button type="button" :class="iconBtn" :title="t('settings.users.reset_password')" :aria-label="`${t('settings.users.reset_password')} ${row.name}`" @click="openReset(row)">
                            <KeyRound class="size-3.5" />
                        </button>
                        <button
                            v-if="row.is_active"
                            type="button"
                            :class="[iconBtn, 'hover:text-red-700']"
                            :title="t('settings.users.deactivate')"
                            :aria-label="`${t('settings.users.deactivate')} ${row.name}`"
                            @click="crud.remove(row.id, t('settings.users.deactivate_confirm', { name: row.name }), 'settings.users.deactivated')"
                        >
                            <UserX class="size-3.5" />
                        </button>
                    </span>
                </template>
            </DataTable>
        </div>

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
                <span class="text-xs font-medium">{{ t('settings.users.new_password') }}</span>
                <input v-model="newPassword" type="password" dir="ltr" required minlength="8" autocomplete="new-password" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm" />
            </label>
        </FormDialog>
    </AppLayout>
</template>
