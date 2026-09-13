<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { setCurrentLocale, useI18n } from '@/composables/useI18n';
import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Languages } from 'lucide-vue-next';

defineProps<{
    title?: string;
    description?: string;
}>();

const { t, locale } = useI18n();
const page = usePage<SharedData>();

// Guests keep the choice in the session; signed-in users (verify/confirm pages) save it on their account.
function toggleLocale(): void {
    const next = locale.value === 'ar' ? 'en' : 'ar';
    setCurrentLocale(next);
    router.post(page.props.auth?.user ? `/locale/${next}` : `/guest/locale/${next}`, {}, { preserveScroll: true });
}
</script>

<template>
    <div class="relative flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
        <button
            type="button"
            class="absolute end-4 top-4 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm text-muted-foreground hover:bg-accent hover:text-foreground"
            @click="toggleLocale"
        >
            <Languages class="size-4" />
            {{ t('auth.switch_language') }}
        </button>

        <div class="w-full max-w-sm">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link :href="route('login')" class="flex flex-col items-center gap-2 font-medium">
                        <div class="mb-1 flex size-12 items-center justify-center rounded-xl bg-primary text-primary-foreground">
                            <AppLogoIcon class="size-8" />
                        </div>
                        <span class="text-lg font-semibold">{{ t('app.name') }}</span>
                        <span class="text-center text-xs text-muted-foreground">{{ t('app.tagline') }}</span>
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="text-xl font-medium">{{ title }}</h1>
                        <p class="text-center text-sm text-muted-foreground">{{ description }}</p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
