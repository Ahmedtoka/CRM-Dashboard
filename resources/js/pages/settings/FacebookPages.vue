<script setup lang="ts">
import PageHeader from '@/components/crm/PageHeader.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type { FacebookPageOption } from '@/types/admin';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Facebook, LoaderCircle, ShieldAlert, ShieldCheck } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ pages: FacebookPageOption[] }>();

const { t, dir } = useI18n();

const busyPage = ref<string | null>(null);

// Connected page first, then connectable ones, then the ones lacking access.
const sortedPages = computed(() =>
    [...props.pages].sort((a, b) => Number(b.connected) - Number(a.connected) || a.missing_tasks.length - b.missing_tasks.length || a.name.localeCompare(b.name)),
);

function initials(name: string): string {
    return name.trim().charAt(0).toUpperCase() || '?';
}

function missingLabel(page: FacebookPageOption): string {
    return page.missing_tasks.map((task) => t(`settings.channels.facebook.task.${task}`)).join(dir.value === 'rtl' ? '، ' : ', ');
}

function connect(page: FacebookPageOption): void {
    if (busyPage.value || page.missing_tasks.length) return;
    busyPage.value = page.id;
    router.post(`/settings/channels/facebook/pages/${page.id}`, {}, { onFinish: () => (busyPage.value = null) });
}

const breadcrumbs = computed(() => [
    { title: t('settings.channels.title'), href: '/settings/channels' },
    { title: t('settings.channels.facebook.pages_title'), href: '/settings/channels/facebook/pages' },
]);
</script>

<template>
    <Head :title="t('settings.channels.facebook.pages_title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('settings.channels.facebook.pages_title')" :description="t('settings.channels.facebook.pages_description')">
                <Link href="/settings/channels" class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-3 text-xs font-medium hover:bg-muted">
                    <component :is="dir === 'rtl' ? ArrowRight : ArrowLeft" class="size-3.5" aria-hidden="true" />
                    {{ t('settings.channels.facebook.back') }}
                </Link>
            </PageHeader>

            <div class="flex flex-wrap items-center gap-2 rounded-lg border border-[#0866FF]/25 bg-[#0866FF]/5 px-3 py-2 text-xs">
                <Facebook class="size-4 shrink-0 text-[#0866FF]" aria-hidden="true" />
                <span class="min-w-0 flex-1">{{ t('settings.channels.facebook.pages_note') }}</span>
                <span class="font-medium text-muted-foreground tabular-nums">{{ t('settings.channels.facebook.pages_count', { count: pages.length }) }}</span>
            </div>

            <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <li
                    v-for="fbPage in sortedPages"
                    :key="fbPage.id"
                    class="flex min-w-0 flex-col gap-3 rounded-lg bg-card p-4 text-xs shadow-card"
                    :class="fbPage.connected ? 'ring-2 ring-[#0866FF]/60' : ''"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <img
                            v-if="fbPage.picture"
                            :src="fbPage.picture"
                            :alt="fbPage.name"
                            class="size-12 shrink-0 rounded-full border border-border object-cover"
                            referrerpolicy="no-referrer"
                        />
                        <span v-else class="flex size-12 shrink-0 items-center justify-center rounded-full bg-[#0866FF]/10 text-lg font-bold text-[#0866FF]" aria-hidden="true">
                            {{ initials(fbPage.name) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-foreground" :title="fbPage.name">{{ fbPage.name }}</p>
                            <p v-if="fbPage.category" class="truncate text-muted-foreground">{{ fbPage.category }}</p>
                            <p class="truncate text-2xs text-muted-foreground" dir="ltr">ID {{ fbPage.id }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        <StatusChip v-if="fbPage.connected" :label="t('settings.channels.facebook.connected_badge')" tone="positive" />
                        <span v-if="!fbPage.missing_tasks.length" class="inline-flex h-5 items-center gap-1 rounded-full bg-muted px-2 text-2xs text-muted-foreground">
                            <ShieldCheck class="size-3" aria-hidden="true" />
                            {{ t('settings.channels.facebook.task.MESSAGING') }} · {{ t('settings.channels.facebook.task.MODERATE') }}
                        </span>
                    </div>

                    <p v-if="fbPage.missing_tasks.length" class="flex items-start gap-1.5 rounded-md bg-warning/15 px-2 py-1.5 text-2xs leading-relaxed">
                        <ShieldAlert class="mt-px size-3.5 shrink-0" aria-hidden="true" />
                        <span>{{ t('settings.channels.facebook.missing_tasks', { tasks: missingLabel(fbPage) }) }}</span>
                    </p>

                    <button
                        type="button"
                        class="mt-auto inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-50"
                        :class="fbPage.connected ? 'border border-[#0866FF]/40 text-[#0866FF] hover:bg-[#0866FF]/5' : 'bg-[#0866FF] text-white shadow-sm hover:bg-[#0759E0]'"
                        :disabled="busyPage !== null || fbPage.missing_tasks.length > 0"
                        @click="connect(fbPage)"
                    >
                        <LoaderCircle v-if="busyPage === fbPage.id" class="size-4 animate-spin" aria-hidden="true" />
                        <Facebook v-else class="size-4" aria-hidden="true" />
                        {{ fbPage.connected ? t('settings.channels.facebook.reconnect_page') : t('settings.channels.facebook.connect_page') }}
                    </button>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
