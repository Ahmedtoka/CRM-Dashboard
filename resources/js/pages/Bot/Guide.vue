<script setup lang="ts">
import PageHeader from '@/components/crm/PageHeader.vue';
import SectionTabs from '@/components/crm/SectionTabs.vue';
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { sectionTabs } from '@/lib/sectionTabs';
import { Head } from '@inertiajs/vue3';

/** «إزاي نطوّر البوت» (b2): the guide, rendered on the server from resources/docs/bot-guide.md (repo content, HTML stripped). */
defineProps<{ html: string }>();

const { t } = useI18n();
</script>

<template>
    <Head :title="t('bot_brain.guide.title')" />

    <AppLayout
        :breadcrumbs="[
            { title: t('nav.bot'), href: '/bot' },
            { title: t('bot_brain.guide.title'), href: '/bot/guide' },
        ]"
    >
        <div class="mx-auto w-full max-w-6xl space-y-4 p-3 md:p-6">
            <PageHeader
                :title="t('bot_brain.guide.title')"
                :description="t('bot_brain.guide.hint')"
                :breadcrumbs="[{ label: t('nav.bot'), href: '/bot' }, { label: t('bot_brain.guide.title') }]"
            />
            <SectionTabs :tabs="sectionTabs.botBrain(t)" :label="t('bot_brain.nav.strip')" />

            <!-- eslint-disable-next-line vue/no-v-html -- trusted repo markdown, rendered with html_input=strip -->
            <article class="bot-guide max-w-3xl rounded-lg border border-line bg-card px-5 py-5 md:px-8 md:py-7" v-html="html" />
        </div>
    </AppLayout>
</template>

<style scoped>
.bot-guide :deep(h1) {
    display: none;
}
.bot-guide :deep(h2) {
    margin-top: 2rem;
    font-size: 1.125rem;
    font-weight: 700;
    line-height: 1.35;
    text-wrap: balance;
}
.bot-guide :deep(h2:first-of-type) {
    margin-top: 0;
}
.bot-guide :deep(h3) {
    margin-top: 1.25rem;
    font-size: 1rem;
    font-weight: 700;
}
.bot-guide :deep(p),
.bot-guide :deep(li) {
    font-size: 0.875rem;
    line-height: 1.8;
}
.bot-guide :deep(p) {
    margin-top: 0.5rem;
}
.bot-guide :deep(ul),
.bot-guide :deep(ol) {
    margin-top: 0.5rem;
    padding-inline-start: 1.25rem;
}
.bot-guide :deep(ul) {
    list-style: disc;
}
.bot-guide :deep(ol) {
    list-style: arabic-indic;
}
.bot-guide :deep(code) {
    border-radius: 4px;
    background: var(--sunken);
    padding: 0 0.3em;
    font-size: 0.8125rem;
    direction: ltr;
    unicode-bidi: isolate;
}
.bot-guide :deep(table) {
    display: block;
    overflow-x: auto;
    margin-top: 0.75rem;
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8125rem;
}
.bot-guide :deep(th),
.bot-guide :deep(td) {
    border-bottom: 1px solid var(--line);
    padding: 0.5rem;
    text-align: start;
    vertical-align: top;
}
.bot-guide :deep(th) {
    color: var(--fg-2);
    font-weight: 600;
}
.bot-guide :deep(a) {
    color: var(--brand);
    text-decoration: underline;
    text-underline-offset: 2px;
}
.bot-guide :deep(blockquote) {
    margin-top: 0.75rem;
    border-inline-start: 3px solid var(--brand);
    background: var(--brand-soft);
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
}
</style>
