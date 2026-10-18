<script setup lang="ts">
import EmptyState from '@/components/crm/EmptyState.vue';
import SettingsPanel from '@/components/crm/settings/SettingsPanel.vue';
import SettingsShell from '@/components/crm/settings/SettingsShell.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { Button, buttonVariants } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { useSettingsBreadcrumbs } from '@/composables/useSettingsBreadcrumbs';
import AppLayout from '@/layouts/AppLayout.vue';
import { cn } from '@/lib/utils';
import type { FacebookPageOption } from '@/types/admin';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Facebook, ShieldAlert, ShieldCheck } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ pages: FacebookPageOption[] }>();

const { t, dir } = useI18n();

const busyPage = ref<string | null>(null);

// Connected page first, then connectable ones, then the ones lacking access.
const sortedPages = computed(() =>
    [...props.pages].sort(
        (a, b) => Number(b.connected) - Number(a.connected) || a.missing_tasks.length - b.missing_tasks.length || a.name.localeCompare(b.name),
    ),
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

const title = computed(() => t('settings.channels.facebook.pages_title'));
const breadcrumbs = useSettingsBreadcrumbs(() => title.value);
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsShell :title="title" :description="t('settings.channels.facebook.pages_description')" width="form">
            <template #actions>
                <Link href="/settings/integrations" :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'max-sm:h-10')">
                    <ArrowLeft class="rtl-flip" aria-hidden="true" />
                    {{ t('settings.channels.facebook.back') }}
                </Link>
            </template>

            <SettingsPanel flush>
                <p class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3 text-13 text-fg-2">
                    <Facebook class="size-4 shrink-0" aria-hidden="true" />
                    <span class="min-w-0 flex-1">{{ t('settings.channels.facebook.pages_note') }}</span>
                    <span class="font-semibold tabular-nums">{{ t('settings.channels.facebook.pages_count', { count: pages.length }) }}</span>
                </p>

                <EmptyState v-if="!pages.length" :icon="Facebook" :title="t('settings.channels.facebook.flash.no_pages')">
                    <template #action>
                        <Link href="/settings/integrations" :class="buttonVariants({ variant: 'outline', size: 'sm' })">{{
                            t('settings.channels.facebook.back')
                        }}</Link>
                    </template>
                </EmptyState>

                <ul v-else>
                    <li
                        v-for="fbPage in sortedPages"
                        :key="fbPage.id"
                        class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-line px-4 py-3.5 last:border-b-0"
                        :class="fbPage.connected ? 'bg-brand-soft/40' : ''"
                        :data-page-id="fbPage.id"
                    >
                        <img
                            v-if="fbPage.picture"
                            :src="fbPage.picture"
                            alt=""
                            class="size-10 shrink-0 rounded-full border border-line object-cover"
                            referrerpolicy="no-referrer"
                        />
                        <span
                            v-else
                            class="grid size-10 shrink-0 place-items-center rounded-full bg-sunken text-base font-bold text-fg-2"
                            aria-hidden="true"
                        >
                            {{ initials(fbPage.name) }}
                        </span>

                        <div class="min-w-[min(100%,12rem)] flex-1">
                            <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <bdi class="truncate text-sm font-semibold text-foreground" :title="fbPage.name">{{ fbPage.name }}</bdi>
                                <StatusChip v-if="fbPage.connected" :label="t('settings.channels.facebook.connected_badge')" tone="ok" size="sm" />
                            </p>
                            <p class="mt-0.5 truncate text-13 text-fg-2">
                                <template v-if="fbPage.category">{{ fbPage.category }} · </template
                                ><bdi dir="ltr" class="tabular-nums">ID {{ fbPage.id }}</bdi>
                            </p>
                            <p
                                v-if="fbPage.missing_tasks.length"
                                class="mt-1.5 flex items-start gap-1.5 rounded-md bg-warn-soft px-2.5 py-1.5 text-xs leading-relaxed"
                            >
                                <ShieldAlert class="mt-px size-3.5 shrink-0 text-warn" aria-hidden="true" />
                                <span>{{ t('settings.channels.facebook.missing_tasks', { tasks: missingLabel(fbPage) }) }}</span>
                            </p>
                            <p v-else class="mt-1 flex items-center gap-1 text-xs text-fg-3">
                                <ShieldCheck class="size-3.5" aria-hidden="true" />
                                {{ t('settings.channels.facebook.task.MESSAGING') }} · {{ t('settings.channels.facebook.task.MODERATE') }}
                            </p>
                        </div>

                        <Button
                            type="button"
                            :variant="fbPage.connected ? 'outline' : 'default'"
                            class="h-10 shrink-0 sm:h-9"
                            :loading="busyPage === fbPage.id"
                            :disabled="busyPage !== null || fbPage.missing_tasks.length > 0"
                            @click="connect(fbPage)"
                        >
                            <Facebook aria-hidden="true" />
                            {{ fbPage.connected ? t('settings.channels.facebook.reconnect_page') : t('settings.channels.facebook.connect_page') }}
                        </Button>
                    </li>
                </ul>
            </SettingsPanel>
        </SettingsShell>
    </AppLayout>
</template>
