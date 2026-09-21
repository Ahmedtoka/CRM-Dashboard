<script setup lang="ts">
/**
 * «كل ردود البوت» (owner, 2026-09-22): every reply next to the moment it is given, searchable,
 * with knowledge rows edited in place (the same endpoint as Settings → معلومات البوت).
 */
import PageHeader from '@/components/crm/PageHeader.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Bot, ExternalLink, Pencil, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface ReplyRow {
    id: string;
    section: string;
    when: string;
    reply: string;
    buttons: string[];
    source: 'entry' | 'flow' | 'rule';
    entry_id: number | null;
    key: string;
    active: boolean;
    edit_url: string | null;
}

interface AgentInfo {
    enabled: boolean;
    model: string;
    entry: { id: number; key: string; title: string; body: string; is_active: boolean } | null;
}

const props = defineProps<{ sections: { key: string; rows: ReplyRow[] }[]; agent: AgentInfo }>();

const { t } = useI18n();
const api = useApi();
const toast = useToast();

const breadcrumbs = computed(() => [{ title: t('settings.bot_replies.title'), href: '/settings/bot-replies' }]);
const query = ref('');
const editingId = ref<string | null>(null);
const draft = ref('');
const busy = ref(false);
const error = ref<string | null>(null);

const needle = computed(() => query.value.trim().toLowerCase());

const visible = computed(() =>
    props.sections
        .map((s) => ({
            ...s,
            rows: needle.value === '' ? s.rows : s.rows.filter((r) => `${r.when}\n${r.reply}\n${r.buttons.join(' ')}`.toLowerCase().includes(needle.value)),
        }))
        .filter((s) => s.rows.length > 0),
);

const total = computed(() => visible.value.reduce((n, s) => n + s.rows.length, 0));

function edit(id: string, body: string): void {
    editingId.value = id;
    draft.value = body;
    error.value = null;
}

async function save(entryId: number | null, original: string): Promise<void> {
    if (entryId === null || draft.value.trim() === '' || draft.value === original) {
        editingId.value = null;
        return;
    }

    busy.value = true;
    error.value = null;
    try {
        await api.put(`/settings/bot-knowledge/entries/${entryId}`, { body: draft.value });
        toast.push(t('settings.bot_replies.saved'));
        editingId.value = null;
        router.reload({ only: ['sections', 'agent'] });
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head :title="t('settings.bot_replies.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('settings.bot_replies.title')" :description="t('settings.bot_replies.description')" />

            <!-- The agent: on/off, its model, and the owner's own instructions to it. -->
            <section class="space-y-2 rounded-lg border border-border bg-card p-4 shadow-card">
                <div class="flex flex-wrap items-center gap-2">
                    <Bot class="size-4 text-primary" aria-hidden="true" />
                    <h2 class="text-sm font-semibold text-foreground">{{ t('settings.bot_replies.agent_title') }}</h2>
                    <span
                        class="rounded-full px-2 py-0.5 text-2xs font-medium"
                        :class="agent.enabled ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' : 'bg-muted text-muted-foreground'"
                        dir="ltr"
                        >{{ agent.model }}</span
                    >
                </div>
                <p class="text-xs text-muted-foreground">{{ agent.enabled ? t('settings.bot_replies.agent_on') : t('settings.bot_replies.agent_off') }}</p>

                <div v-if="agent.entry" class="space-y-1.5 border-t border-border pt-2">
                    <p class="text-xs font-semibold text-foreground">{{ t('settings.bot_replies.agent_instructions') }}</p>
                    <template v-if="editingId === 'agent'">
                        <textarea v-model="draft" dir="auto" rows="5" maxlength="5000" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm" />
                        <p v-if="error" role="alert" class="text-xs text-destructive">{{ error }}</p>
                        <div class="flex gap-2">
                            <button type="button" class="h-8 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground disabled:opacity-50" :disabled="busy" @click="save(agent.entry.id, agent.entry.body)">
                                {{ t('settings.bot_replies.save') }}
                            </button>
                            <button type="button" class="h-8 rounded-md border border-input px-3 text-xs" :disabled="busy" @click="editingId = null">{{ t('settings.bot_replies.cancel') }}</button>
                        </div>
                    </template>
                    <template v-else>
                        <p class="text-sm whitespace-pre-line text-foreground" dir="auto">{{ agent.entry.body }}</p>
                        <button type="button" class="inline-flex h-7 items-center gap-1 rounded-md border border-input px-2 text-xs" @click="edit('agent', agent.entry.body)">
                            <Pencil class="size-3" aria-hidden="true" />{{ t('settings.bot_replies.edit') }}
                        </button>
                    </template>
                </div>
            </section>

            <div class="sticky top-0 z-10 flex items-center gap-2 rounded-lg border border-border bg-card p-2 shadow-card">
                <Search class="ms-1 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <input v-model="query" type="search" dir="auto" :placeholder="t('settings.bot_replies.search')" :aria-label="t('settings.bot_replies.search')" class="h-8 w-full bg-transparent text-sm outline-none" />
                <span class="shrink-0 pe-1 text-2xs text-muted-foreground">{{ t('settings.bot_replies.count', { n: total }) }}</span>
            </div>

            <p v-if="visible.length === 0" class="py-8 text-center text-sm text-muted-foreground">{{ t('settings.bot_replies.empty') }}</p>

            <section v-for="section in visible" :key="section.key" class="overflow-hidden rounded-lg border border-border bg-card shadow-card">
                <h2 class="flex items-center justify-between border-b border-border bg-muted/40 px-4 py-2 text-sm font-semibold text-foreground">
                    {{ t(`settings.bot_replies.sections.${section.key}`) }}
                    <span class="text-2xs font-normal text-muted-foreground">{{ section.rows.length }}</span>
                </h2>

                <div v-for="row in section.rows" :key="row.id" class="grid gap-2 border-b border-border px-4 py-3 last:border-b-0 md:grid-cols-[minmax(0,2fr)_minmax(0,3fr)_auto]" :class="{ 'opacity-60': !row.active }">
                    <div class="min-w-0">
                        <p class="text-2xs font-semibold text-muted-foreground">{{ t('settings.bot_replies.when') }}</p>
                        <p class="text-sm text-foreground" dir="auto">{{ row.when }}</p>
                        <span v-if="!row.active" class="mt-1 inline-block rounded-full bg-muted px-2 py-0.5 text-2xs text-muted-foreground">{{ t('settings.bot_replies.inactive') }}</span>
                    </div>

                    <div class="min-w-0">
                        <p class="text-2xs font-semibold text-muted-foreground">{{ t('settings.bot_replies.reply') }}</p>
                        <template v-if="editingId === row.id">
                            <textarea v-model="draft" dir="auto" rows="5" maxlength="5000" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm" />
                            <p v-if="error" role="alert" class="text-xs text-destructive">{{ error }}</p>
                            <div class="mt-1 flex gap-2">
                                <button type="button" class="h-8 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground disabled:opacity-50" :disabled="busy" @click="save(row.entry_id, row.reply)">
                                    {{ t('settings.bot_replies.save') }}
                                </button>
                                <button type="button" class="h-8 rounded-md border border-input px-3 text-xs" :disabled="busy" @click="editingId = null">{{ t('settings.bot_replies.cancel') }}</button>
                            </div>
                        </template>
                        <template v-else>
                            <p class="text-sm break-words whitespace-pre-line text-foreground" dir="auto">{{ row.reply }}</p>
                            <p v-if="row.buttons.length" class="mt-1.5 flex flex-wrap gap-1" :aria-label="t('settings.bot_replies.buttons')">
                                <span v-for="(b, i) in row.buttons" :key="i" class="rounded-full border border-border px-2 py-0.5 text-2xs text-muted-foreground" dir="auto">{{ b }}</span>
                            </p>
                        </template>
                    </div>

                    <div class="flex items-start justify-end">
                        <button v-if="row.source === 'entry' && editingId !== row.id" type="button" class="inline-flex h-7 items-center gap-1 rounded-md border border-input px-2 text-xs" @click="edit(row.id, row.reply)">
                            <Pencil class="size-3" aria-hidden="true" />{{ t('settings.bot_replies.edit') }}
                        </button>
                        <Link v-else-if="row.edit_url" :href="row.edit_url" class="inline-flex h-7 items-center gap-1 rounded-md border border-input px-2 text-xs">
                            <ExternalLink class="size-3" aria-hidden="true" />{{ t('settings.bot_replies.open_editor') }}
                        </Link>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
