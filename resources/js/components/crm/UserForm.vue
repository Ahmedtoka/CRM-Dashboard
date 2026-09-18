<script setup lang="ts">
import FormDialog from '@/components/crm/FormDialog.vue';
import PlatformCheckboxes from '@/components/crm/PlatformCheckboxes.vue';
import { useI18n } from '@/composables/useI18n';
import type { ManagedUser } from '@/types/admin';
import type { PlatformValue, Role } from '@/types/crm';
import { computed, reactive, watch } from 'vue';

const props = defineProps<{ open: boolean; user: ManagedUser | null; roles: Role[]; busy: boolean; error: string | null }>();
const emit = defineEmits<{ 'update:open': [open: boolean]; submit: [id: number | null, payload: Record<string, unknown>] }>();

const { t } = useI18n();

const form = reactive({
    name: '',
    email: '',
    password: '',
    role: 'moderator' as Role,
    color: '#6366f1',
    locale: 'ar' as 'ar' | 'en',
    is_active: true,
    platforms: [] as PlatformValue[],
});

// Reset the form each time the dialog opens (create or edit).
watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const u = props.user;
        Object.assign(form, {
            name: u?.name ?? '',
            email: u?.email ?? '',
            password: '',
            role: u?.role ?? 'moderator',
            color: u?.color ?? '#6366f1',
            locale: u?.locale ?? 'ar',
            is_active: u?.is_active ?? true,
            platforms: [...(u?.platforms ?? [])],
        });
    },
    { immediate: true },
);

const isModerator = computed(() => form.role === 'moderator');

function submit(): void {
    const payload: Record<string, unknown> = { ...form };
    if (props.user && !form.password) delete payload.password;
    emit('submit', props.user?.id ?? null, payload);
}

const input = 'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';
</script>

<template>
    <FormDialog :open="open" :title="user ? t('settings.users.edit') : t('settings.users.add')" :busy="busy" :error="error" @update:open="emit('update:open', $event)" @submit="submit">
        <label class="grid gap-1">
            <span class="text-sm font-semibold">{{ t('settings.users.name') }}</span>
            <input v-model="form.name" :class="input" required maxlength="255" autocomplete="off" />
        </label>
        <label class="grid gap-1">
            <span class="text-sm font-semibold">{{ t('settings.users.email') }}</span>
            <input v-model="form.email" type="email" dir="ltr" :class="input" required autocomplete="off" />
        </label>
        <label v-if="!user" class="grid gap-1">
            <span class="text-sm font-semibold">{{ t('settings.users.password') }}</span>
            <input v-model="form.password" type="password" dir="ltr" :class="input" required minlength="8" autocomplete="new-password" />
        </label>
        <div class="grid grid-cols-3 gap-2">
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.users.role') }}</span>
                <select v-model="form.role" :class="input">
                    <option v-for="role in roles" :key="role" :value="role">{{ t(`roles.${role}`) }}</option>
                </select>
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.users.locale') }}</span>
                <select v-model="form.locale" :class="input">
                    <option value="ar">العربية</option>
                    <option value="en">English</option>
                </select>
            </label>
            <label class="grid gap-1">
                <span class="text-sm font-semibold">{{ t('settings.users.color') }}</span>
                <input v-model="form.color" type="color" class="h-9 w-full rounded-md border border-input bg-background p-1" />
            </label>
        </div>
        <PlatformCheckboxes v-model="form.platforms" :legend="t('settings.users.platforms')" :hint="isModerator ? undefined : t('settings.users.platforms_hint')" />
        <label class="flex items-center gap-2 text-xs">
            <input v-model="form.is_active" type="checkbox" class="rounded border-input" />{{ t('settings.users.active') }}
        </label>
    </FormDialog>
</template>
