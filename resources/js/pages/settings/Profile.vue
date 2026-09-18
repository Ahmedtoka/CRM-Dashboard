<script setup lang="ts">
import { TransitionRoot } from '@headlessui/vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem, type SharedData, type User } from '@/types';
import { computed } from 'vue';

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
    className?: string;
}

defineProps<Props>();

const { t } = useI18n();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [{ title: t('nav.settings_layout.profile'), href: '/settings/profile' }]);

const page = usePage<SharedData>();
const user = page.props.auth.user as User;

const form = useForm({
    name: user.name,
    email: user.email,
});

const submit = () => {
    form.patch(route('profile.update'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('nav.settings_layout.profile')" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall :title="t('nav.settings_layout.profile_heading')" :description="t('nav.settings_layout.profile_hint')" />

                <form @submit.prevent="submit" class="space-y-6 rounded-lg bg-card p-4 shadow-card">
                    <div class="grid gap-2">
                        <Label for="name">{{ t('nav.settings_layout.name') }}</Label>
                        <Input
                            id="name"
                            class="mt-1 block w-full"
                            v-model="form.name"
                            required
                            autocomplete="name"
                            :placeholder="t('nav.settings_layout.name_placeholder')"
                        />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">{{ t('nav.settings_layout.email') }}</Label>
                        <Input
                            id="email"
                            type="email"
                            class="mt-1 block w-full"
                            v-model="form.email"
                            required
                            autocomplete="username"
                            :placeholder="t('nav.settings_layout.email_placeholder')"
                        />
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>

                    <div v-if="mustVerifyEmail && !user.email_verified_at">
                        <p class="mt-2 text-sm text-foreground">
                            {{ t('nav.settings_layout.unverified') }}
                            <Link
                                :href="route('verification.send')"
                                method="post"
                                as="button"
                                class="focus:outline-hidden rounded-md text-sm text-muted-foreground underline hover:text-foreground focus:ring-2 focus:ring-offset-2"
                            >
                                {{ t('nav.settings_layout.resend_verification') }}
                            </Link>
                        </p>

                        <div v-if="status === 'verification-link-sent'" class="mt-2 text-sm font-medium text-success">
                            {{ t('nav.settings_layout.verification_sent') }}
                        </div>
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
                        <Button :disabled="form.processing">{{ t('nav.settings_layout.save') }}</Button>
                    </div>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
