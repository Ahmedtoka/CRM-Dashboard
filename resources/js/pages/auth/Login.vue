<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

interface QuickUser {
    id: number;
    name: string;
    email: string;
    role: string;
}

const props = withDefaults(
    defineProps<{
        status?: string;
        canResetPassword: boolean;
        /** Local-only one-click sign-in; always empty on a real server. */
        quickUsers?: QuickUser[];
    }>(),
    { quickUsers: () => [] },
);

const { t } = useI18n();

const form = useForm({
    email: '',
    password: '',
    // A login that lasts weeks instead of asking again every couple of hours.
    remember: true,
});

const quickForm = useForm({ user_id: 0 });

const quickLogin = (user: QuickUser) => {
    quickForm.user_id = user.id;
    quickForm.post(route('login.quick'));
};

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthBase :title="t('auth.login.title')" :description="t('auth.login.description')">
        <Head :title="t('auth.login.title')" />

        <div v-if="status" class="mb-4 text-center text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <div v-if="props.quickUsers.length" class="mb-6 rounded-lg border border-dashed border-primary/40 bg-primary/5 p-3">
            <p class="mb-2 text-xs font-medium text-muted-foreground">{{ t('auth.quick_login.title') }}</p>
            <div class="flex flex-col gap-2">
                <button
                    v-for="user in props.quickUsers"
                    :key="user.id"
                    type="button"
                    class="flex items-center justify-between gap-3 rounded-md border border-border bg-background px-3 py-2 text-start transition hover:border-primary hover:bg-elevated disabled:opacity-60"
                    :disabled="quickForm.processing"
                    @click="quickLogin(user)"
                >
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold">{{ user.name }}</span>
                        <span class="block truncate text-xs text-muted-foreground" dir="ltr">{{ user.email }}</span>
                    </span>
                    <span class="flex shrink-0 items-center gap-2">
                        <span class="rounded-full bg-elevated px-2 py-0.5 text-2xs text-muted-foreground">{{ t(`roles.${user.role}`) }}</span>
                        <LoaderCircle v-if="quickForm.processing && quickForm.user_id === user.id" class="h-4 w-4 animate-spin" />
                        <span v-else class="text-xs font-medium text-primary">{{ t('auth.quick_login.enter') }}</span>
                    </span>
                </button>
            </div>
            <p class="mt-2 text-2xs text-muted-foreground">{{ t('auth.quick_login.note') }}</p>
        </div>

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">{{ t('auth.email') }}</Label>
                    <Input
                        id="email"
                        type="email"
                        dir="ltr"
                        required
                        autofocus
                        tabindex="1"
                        autocomplete="email"
                        v-model="form.email"
                        :placeholder="t('auth.email_placeholder')"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">{{ t('auth.password') }}</Label>
                        <TextLink v-if="canResetPassword" :href="route('password.request')" class="text-sm" :tabindex="5">
                            {{ t('auth.forgot') }}
                        </TextLink>
                    </div>
                    <Input
                        id="password"
                        type="password"
                        dir="ltr"
                        required
                        tabindex="2"
                        autocomplete="current-password"
                        v-model="form.password"
                        :placeholder="t('auth.password')"
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="flex items-center justify-between" tabindex="3">
                    <Label for="remember" class="flex items-center gap-3">
                        <Checkbox id="remember" v-model:checked="form.remember" tabindex="4" />
                        <span>{{ t('auth.remember') }}</span>
                    </Label>
                </div>

                <Button type="submit" class="mt-4 w-full" tabindex="4" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    {{ t('auth.login.submit') }}
                </Button>
            </div>
        </form>
    </AuthBase>
</template>
