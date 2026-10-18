<script setup lang="ts">
import BotSettingsForm from '@/components/crm/BotSettingsForm.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BotSettings } from '@/types/admin';
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, GraduationCap, ListTree, TrendingUp, Workflow } from 'lucide-vue-next';
import { computed } from 'vue';

// The old keyword rules were replaced by the main menu + guided flows, so this page
// now only holds the bot's running, timing, hours, word lists and AI settings.
const props = defineProps<{ settings: BotSettings; canEditAi: boolean }>();

const { t } = useI18n();

const title = computed(() => t('nav.bot_run'));
const breadcrumbs = computed(() => [
    { title: t('nav.bot'), href: '/bot' },
    { title: title.value, href: '/bot' },
]);
const headerCrumbs = computed(() => [{ label: t('nav.bot'), href: '/bot' }, { label: title.value }]);

/** The side panel: what is saved right now, in one glance (the form above may hold unsaved edits). */
const hours = computed(() => {
    const h = props.settings.working_hours;
    return h?.from && h?.to ? `${h.from} – ${h.to}` : null;
});

const links = computed(() => [
    { href: '/bot/flows', label: t('settings.bot.flows_link'), icon: Workflow },
    { href: '/bot/intents', label: t('settings.bot.intents_link'), icon: ListTree },
    { href: '/bot/learning', label: t('nav.bot_learning'), icon: GraduationCap },
    { href: '/bot/performance', label: t('nav.bot_performance'), icon: TrendingUp },
]);
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-4 p-3 md:p-6">
            <PageHeader :title="title" :description="t('settings.bot.page_hint')" :breadcrumbs="headerCrumbs">
                <template #extra>
                    <StatusChip
                        :label="settings.enabled ? t('settings.bot.side.on') : t('settings.bot.side.off')"
                        :tone="settings.enabled ? 'ok' : 'danger'"
                        dot
                        data-bot-status
                    />
                </template>
            </PageHeader>

            <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
                <BotSettingsForm :settings="settings" :can-edit-ai="canEditAi" />

                <aside class="space-y-4 lg:sticky lg:top-20">
                    <section class="rounded-lg border border-line bg-card" aria-labelledby="bot-side-title">
                        <h2 id="bot-side-title" class="border-b border-line px-4 pb-2.5 pt-3.5 text-base font-bold">
                            {{ t('settings.bot.side.title') }}
                        </h2>
                        <dl class="px-4 py-1 text-13">
                            <div class="flex items-center justify-between gap-3 border-b border-line py-2.5">
                                <dt class="text-fg-2">{{ t('settings.bot.enabled') }}</dt>
                                <dd>
                                    <StatusChip
                                        size="sm"
                                        :label="settings.enabled ? t('settings.bot.side.on') : t('settings.bot.side.off')"
                                        :tone="settings.enabled ? 'ok' : 'danger'"
                                    />
                                </dd>
                            </div>
                            <div class="flex items-center justify-between gap-3 border-b border-line py-2.5">
                                <dt class="text-fg-2">{{ t('settings.bot.cards.ai') }}</dt>
                                <dd>
                                    <StatusChip
                                        size="sm"
                                        :label="settings.ai_enabled ? t('settings.bot.side.on') : t('settings.bot.side.off')"
                                        :tone="settings.ai_enabled ? 'ok' : 'neutral'"
                                    />
                                </dd>
                            </div>
                            <div v-if="settings.ai_reply_model" class="flex items-center justify-between gap-3 border-b border-line py-2.5">
                                <dt class="text-fg-2">{{ t('settings.bot.side.model') }}</dt>
                                <dd class="min-w-0 truncate font-semibold" dir="ltr">{{ settings.ai_reply_model }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-3 py-2.5">
                                <dt class="text-fg-2">{{ t('settings.bot.working_hours') }}</dt>
                                <dd class="font-semibold tabular-nums" :dir="hours ? 'ltr' : undefined">
                                    {{ hours ?? t('settings.bot.side.always') }}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <nav class="rounded-lg border border-line bg-card py-1" :aria-label="t('nav.bot')">
                        <Link
                            v-for="link in links"
                            :key="link.href"
                            :href="link.href"
                            class="flex min-h-10 items-center gap-2.5 px-4 py-2 text-sm font-medium text-foreground transition-colors hover:bg-surface-2"
                        >
                            <component :is="link.icon" class="size-4 text-brand-ink" aria-hidden="true" />
                            <span class="flex-1">{{ link.label }}</span>
                            <ChevronRight class="rtl-flip size-4 text-fg-3" aria-hidden="true" />
                        </Link>
                    </nav>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
