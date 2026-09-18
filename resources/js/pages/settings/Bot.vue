<script setup lang="ts">
import BotSettingsForm from '@/components/crm/BotSettingsForm.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BotSettings } from '@/types/admin';
import { Head, Link } from '@inertiajs/vue3';
import { ListTree, Workflow } from 'lucide-vue-next';
import { computed } from 'vue';

// The old keyword rules were replaced by the main menu + guided flows, so this page
// now only holds the bot's running, timing, hours, word lists and AI settings.
defineProps<{ settings: BotSettings; canEditAi: boolean }>();

const { t } = useI18n();

const breadcrumbs = computed(() => [{ title: t('settings.bot.title'), href: '/settings/bot' }]);
const linkClass =
    'inline-flex h-9 items-center gap-1.5 rounded-md border border-border bg-card px-3 text-xs font-medium text-foreground hover:bg-muted';
</script>

<template>
    <Head :title="t('settings.bot.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-4xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('settings.bot.title')" :description="t('settings.bot.page_hint')">
                <Link href="/settings/bot-intents" :class="linkClass">
                    <ListTree class="size-3.5 text-primary" aria-hidden="true" />{{ t('settings.bot.intents_link') }}
                </Link>
                <Link href="/settings/bot-flows" :class="linkClass">
                    <Workflow class="size-3.5 text-primary" aria-hidden="true" />{{ t('settings.bot.flows_link') }}
                </Link>
            </PageHeader>

            <BotSettingsForm :settings="settings" :can-edit-ai="canEditAi" />
        </div>
    </AppLayout>
</template>
