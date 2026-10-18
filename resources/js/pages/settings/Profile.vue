<script setup lang="ts">
import { TransitionRoot } from '@headlessui/vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

import InputError from '@/components/InputError.vue';
import SettingsPanel from '@/components/crm/settings/SettingsPanel.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import { useSettingsBreadcrumbs } from '@/composables/useSettingsBreadcrumbs';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type SharedData, type User } from '@/types';

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
    className?: string;
}

defineProps<Props>();

const { t } = useI18n();

const breadcrumbs = useSettingsBreadcrumbs(() => t('section_tabs.account.profile'));

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
            <SettingsPanel :title="t('nav.settings_layout.profile_heading')" :description="t('nav.settings_layout.profile_hint')">
                <form class="grid max-w-[520px] gap-3.5 py-3" @submit.prevent="submit">
                    <div class="grid gap-1.5">
                        <Label for="name" class="text-13 font-semibold">{{ t('nav.settings_layout.name') }}</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            class="w-full"
                            required
                            autocomplete="name"
                            :placeholder="t('nav.settings_layout.name_placeholder')"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="email" class="text-13 font-semibold">{{ t('nav.settings_layout.email') }}</Label>
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            dir="ltr"
                            class="w-full text-start"
                            required
                            autocomplete="username"
                            :placeholder="t('nav.settings_layout.email_placeholder')"
                        />
                        <InputError :message="form.errors.email" />
                    </div>

                    <div v-if="mustVerifyEmail && !user.email_verified_at" class="rounded-md bg-warn-soft px-3 py-2 text-13 text-warn">
                        <p>
                            {{ t('nav.settings_layout.unverified') }}
                            <Link
                                :href="route('verification.send')"
                                method="post"
                                as="button"
                                class="rounded-sm font-semibold underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                {{ t('nav.settings_layout.resend_verification') }}
                            </Link>
                        </p>

                        <p v-if="status === 'verification-link-sent'" class="mt-1 font-medium text-ok">
                            {{ t('nav.settings_layout.verification_sent') }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3 pt-1">
                        <Button :loading="form.processing">{{ t('nav.settings_layout.save') }}</Button>
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
