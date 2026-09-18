<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { TransitionRoot } from '@headlessui/vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import { type BreadcrumbItem } from '@/types';

interface Props {
    className?: string;
}

defineProps<Props>();

const { t } = useI18n();

const breadcrumbItems = computed<BreadcrumbItem[]>(() => [{ title: t('nav.settings_layout.password'), href: '/settings/password' }]);

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
            <div class="space-y-6">
                <HeadingSmall :title="t('nav.settings_layout.password_heading')" :description="t('nav.settings_layout.password_hint')" />

                <form @submit.prevent="updatePassword" class="space-y-6 rounded-lg bg-card p-4 shadow-card">
                    <div class="grid gap-2">
                        <Label for="current_password">{{ t('nav.settings_layout.current_password') }}</Label>
                        <Input
                            id="current_password"
                            ref="currentPasswordInput"
                            v-model="form.current_password"
                            type="password"
                            class="mt-1 block w-full"
                            autocomplete="current-password"
                            :placeholder="t('nav.settings_layout.current_password')"
                        />
                        <InputError :message="form.errors.current_password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password">{{ t('nav.settings_layout.new_password') }}</Label>
                        <Input
                            id="password"
                            ref="passwordInput"
                            v-model="form.password"
                            type="password"
                            class="mt-1 block w-full"
                            autocomplete="new-password"
                            :placeholder="t('nav.settings_layout.new_password')"
                        />
                        <InputError :message="form.errors.password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password_confirmation">{{ t('nav.settings_layout.confirm_password') }}</Label>
                        <Input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            class="mt-1 block w-full"
                            autocomplete="new-password"
                            :placeholder="t('nav.settings_layout.confirm_password')"
                        />
                        <InputError :message="form.errors.password_confirmation" />
                    </div>

                    <div class="flex items-center justify-end gap-4">
                        <TransitionRoot
                            :show="form.recentlySuccessful"
                            enter="transition ease-in-out"
                            enter-from="opacity-0"
                            leave="transition ease-in-out"
                            leave-to="opacity-0"
                        >
                            <p class="text-sm text-muted-foreground">{{ t('nav.settings_layout.saved') }}</p>
                        </TransitionRoot>
                        <Button :disabled="form.processing">{{ t('nav.settings_layout.save_password') }}</Button>
                    </div>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
