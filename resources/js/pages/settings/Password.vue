<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import SettingsPanel from '@/components/crm/settings/SettingsPanel.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { TransitionRoot } from '@headlessui/vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import { useSettingsBreadcrumbs } from '@/composables/useSettingsBreadcrumbs';

interface Props {
    className?: string;
}

defineProps<Props>();

const { t } = useI18n();

const breadcrumbItems = useSettingsBreadcrumbs(() => t('section_tabs.account.password'));

const passwordInput = ref<HTMLInputElement>();
const currentPasswordInput = ref<HTMLInputElement>();

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: (errors: any) => {
            if (errors.password) {
                form.reset('password', 'password_confirmation');
                if (passwordInput.value instanceof HTMLInputElement) {
                    passwordInput.value.focus();
                }
            }

            if (errors.current_password) {
                form.reset('current_password');
                if (currentPasswordInput.value instanceof HTMLInputElement) {
                    currentPasswordInput.value.focus();
                }
            }
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head :title="t('nav.settings_layout.password')" />

        <SettingsLayout>
            <SettingsPanel :title="t('nav.settings_layout.password_heading')" :description="t('nav.settings_layout.password_hint')">
                <form class="grid max-w-[420px] gap-3.5 py-3" @submit.prevent="updatePassword">
                    <div class="grid gap-1.5">
                        <Label for="current_password" class="text-13 font-semibold">{{ t('nav.settings_layout.current_password') }}</Label>
                        <Input
                            id="current_password"
                            ref="currentPasswordInput"
                            v-model="form.current_password"
                            type="password"
                            class="w-full"
                            autocomplete="current-password"
                        />
                        <InputError :message="form.errors.current_password" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="password" class="text-13 font-semibold">{{ t('nav.settings_layout.new_password') }}</Label>
                        <Input
                            id="password"
                            ref="passwordInput"
                            v-model="form.password"
                            type="password"
                            class="w-full"
                            autocomplete="new-password"
                            aria-describedby="password-help"
                        />
                        <p id="password-help" class="text-xs text-fg-2">{{ t('nav.settings_layout.password_help') }}</p>
                        <InputError :message="form.errors.password" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="password_confirmation" class="text-13 font-semibold">{{ t('nav.settings_layout.confirm_password') }}</Label>
                        <Input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            class="w-full"
                            autocomplete="new-password"
                        />
                        <InputError :message="form.errors.password_confirmation" />
                    </div>

                    <div class="flex flex-wrap items-center gap-3 pt-1">
                        <Button :loading="form.processing">{{ t('nav.settings_layout.save_password') }}</Button>
                        <TransitionRoot
                            :show="form.recentlySuccessful"
                            enter="transition ease-in-out"
                            enter-from="opacity-0"
                            leave="transition ease-in-out"
                            leave-to="opacity-0"
                        >
                            <p class="text-13 text-ok" role="status">{{ t('nav.settings_layout.saved') }}</p>
                        </TransitionRoot>
                    </div>
                </form>
            </SettingsPanel>
        </SettingsLayout>
    </AppLayout>
</template>
