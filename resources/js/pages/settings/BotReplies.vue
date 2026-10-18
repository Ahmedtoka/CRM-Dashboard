<script setup lang="ts">
import SectionTabs from '@/components/crm/SectionTabs.vue';
import { sectionTabs } from '@/lib/sectionTabs';
/**
 * «كل ردود البوت» (owner, 2026-09-22): every reply next to the moment it is given, searchable,
 * with knowledge rows edited in place (the same endpoint as Settings → معلومات البوت).
 */
import EmptyState from '@/components/crm/EmptyState.vue';
import FilterBar from '@/components/crm/FilterBar.vue';
import IconAction from '@/components/crm/IconAction.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import StatusChip from '@/components/crm/StatusChip.vue';
import { Button } from '@/components/ui/button';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount } from '@/lib/format';
import { Head, Link, router } from '@inertiajs/vue3';
import { Bot, ExternalLink, Pencil, RotateCcw, SearchX } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface ReplyRow {
    id: string;
    section: string;
    when: string;
    reply: string;
    buttons: string[];
    source: 'entry' | 'flow' | 'rule' | 'text';
    original?: string | null;
    entry_id: number | null;
    key: string;
    raw?: string;
    active: boolean;
    edit_url: string | null;
}

interface AgentInfo {
    enabled: boolean;
    model: string;
    entry: { id: number; key: string; title: string; body: string; is_active: boolean } | null;
}

const props = defineProps<{ sections: { key: string; rows: ReplyRow[] }[]; agent: AgentInfo }>();

const { t, locale } = useI18n();
const api = useApi();
const toast = useToast();

const breadcrumbs = computed(() => [
    { title: t('nav.bot'), href: '/bot' },
    { title: t('settings.bot_replies.title'), href: '/bot/replies' },
]);
const query = ref('');
/** «المجموعة»: one section, or every section (''). */
const group = ref('');
const editingId = ref<string | null>(null);
const draft = ref('');
const busy = ref(false);
const error = ref<string | null>(null);

const needle = computed(() => query.value.trim().toLowerCase());

const visible = computed(() =>
    props.sections
        .map((s) => ({
            ...s,
            rows:
                needle.value === ''
                    ? s.rows
                    : s.rows.filter((r) => `${r.when}\n${r.reply}\n${r.buttons.join(' ')}`.toLowerCase().includes(needle.value)),
        }))
        .filter((s) => s.rows.length > 0 && (group.value === '' || s.key === group.value)),
);

const total = computed(() => visible.value.reduce((n, s) => n + s.rows.length, 0));

/** «رجّعي الأصل»: drops the owner's wording of a code sentence. */
async function reset(source: string): Promise<void> {
    busy.value = true;
    error.value = null;
    try {
        await api.delete('/settings/bot-replies/text', { data: { source } });
        toast.push(t('settings.bot_replies.saved'));
        router.reload({ only: ['sections'] });
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        busy.value = false;
    }
}

function edit(id: string, body: string): void {
    editingId.value = id;
    draft.value = body;
    error.value = null;
}

async function save(entryId: number | null, original: string, source?: string): Promise<void> {
    if ((entryId === null && !source) || draft.value.trim() === '' || draft.value === original) {
        editingId.value = null;
        return;
    }

    busy.value = true;
    error.value = null;
    try {
        if (source) await api.put('/settings/bot-replies/text', { source, text: draft.value });
        else await api.put(`/settings/bot-knowledge/entries/${entryId}`, { body: draft.value });
        toast.push(t('settings.bot_replies.saved'));
        editingId.value = null;
        router.reload({ only: ['sections', 'agent'] });
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        busy.value = false;
    }
}

/** A reply with its `{placeholder}` / `#{order}` tokens isolated, so they read left-to-right inside Arabic text. */
function replyParts(text: string): { text: string; token: boolean }[] {
    return text
        .split(/(#?{[a-z_]+})/)
        .filter((part) => part !== '')
        .map((part) => ({ text: part, token: /^#?{[a-z_]+}$/.test(part) }));
}
</script>

<template>
    <Head :title="t('settings.bot_replies.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl space-y-4 p-3 md:p-6">
            <PageHeader :title="t('nav.bot_copy')" :description="t('settings.bot_replies.copy_description')" />
            <SectionTabs :tabs="sectionTabs.botCopy(t)" :label="t('nav.bot_copy')" />

            <!-- The agent: on/off, its model, and the owner's own instructions to it. -->
            <section class="rounded-lg border border-line bg-card">
                <div class="flex flex-wrap items-center gap-2 px-4 pb-2 pt-3.5">
                    <Bot class="size-4 text-brand-ink" aria-hidden="true" />
                    <h2 class="text-base font-bold text-foreground">{{ t('settings.bot_replies.agent_title') }}</h2>
                    <StatusChip :label="agent.model" :tone="agent.enabled ? 'ok' : 'neutral'" size="sm" dir="ltr" />
                </div>
                <div class="space-y-2 px-4 pb-4">
                    <p class="text-13 text-fg-2">{{ agent.enabled ? t('settings.bot_replies.agent_on') : t('settings.bot_replies.agent_off') }}</p>

                    <div v-if="agent.entry" class="space-y-1.5 border-t border-line pt-3">
                        <div class="flex items-center gap-2">
                            <p class="text-13 font-semibold text-foreground">{{ t('settings.bot_replies.agent_instructions') }}</p>
                            <IconAction
                                v-if="editingId !== 'agent'"
                                class="ms-auto"
                                :icon="Pencil"
                                :label="t('settings.bot_replies.edit')"
                                @click="edit('agent', agent.entry.body)"
                            />
                        </div>
                        <template v-if="editingId === 'agent'">
                            <textarea
                                v-model="draft"
                                dir="auto"
                                rows="5"
                                maxlength="5000"
                                :aria-label="t('settings.bot_replies.agent_instructions')"
                                class="w-full rounded-md border border-line-strong bg-card px-3 py-2 text-sm focus-visible:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40"
                            />
                            <p v-if="error" role="alert" class="text-13 text-danger">{{ error }}</p>
                            <div class="flex gap-2">
                                <Button type="button" size="sm" :loading="busy" @click="save(agent.entry.id, agent.entry.body)">
                                    {{ t('settings.bot_replies.save') }}
                                </Button>
                                <Button type="button" size="sm" variant="outline" :disabled="busy" @click="editingId = null">{{
                                    t('settings.bot_replies.cancel')
                                }}</Button>
                            </div>
                        </template>
                        <p v-else class="whitespace-pre-line text-[13.5px] leading-[1.75] text-foreground" dir="auto">{{ agent.entry.body }}</p>
                    </div>
                </div>
            </section>

            <FilterBar
                :search="query"
                :search-placeholder="t('settings.bot_replies.search')"
                :chips="[]"
                :summary="t('settings.bot_replies.count', { n: total })"
                @update:search="query = $event"
            >
                <template #inline>
                    <select
                        v-model="group"
                        class="h-9 w-full rounded-md border border-line-strong bg-card px-2 text-13 sm:w-auto"
                        :aria-label="t('settings.bot_replies.group')"
                    >
                        <option value="">{{ t('settings.bot_replies.group_all') }}</option>
                        <option v-for="s in sections" :key="s.key" :value="s.key">{{ t(`settings.bot_replies.sections.${s.key}`) }}</option>
                    </select>
                </template>
            </FilterBar>

            <div v-if="visible.length === 0" class="rounded-lg border border-line bg-card">
                <EmptyState :icon="SearchX" :title="t('settings.bot_replies.empty')" />
            </div>

            <section v-for="section in visible" :key="section.key" class="rounded-lg border border-line bg-card">
                <h2 class="flex items-center gap-2 border-b border-line px-4 pb-2.5 pt-3.5 text-base font-bold text-foreground">
                    {{ t(`settings.bot_replies.sections.${section.key}`) }}
                    <span class="ms-auto text-xs font-normal tabular-nums text-fg-3">{{ formatCount(section.rows.length, locale) }}</span>
                </h2>

                <div
                    v-for="row in section.rows"
                    :key="row.id"
                    class="flex flex-col gap-2 border-b border-line px-4 py-3.5 last:border-b-0"
                    :class="{ 'opacity-60': !row.active }"
                >
                    <div class="flex min-w-0 items-start gap-2">
                        <p class="min-w-0 flex-1 break-words pt-1 text-sm font-semibold text-foreground" dir="auto">{{ row.when }}</p>
                        <StatusChip v-if="!row.active" class="mt-1" :label="t('settings.bot_replies.inactive')" size="sm" />
                        <div
                            v-if="(row.source === 'entry' || row.source === 'text') && editingId !== row.id"
                            class="flex shrink-0 items-center gap-0.5"
                        >
                            <IconAction
                                v-if="row.original"
                                :icon="RotateCcw"
                                :label="t('settings.bot_replies.reset')"
                                :disabled="busy"
                                @click="reset(row.raw ?? row.key)"
                            />
                            <IconAction :icon="Pencil" :label="t('settings.bot_replies.edit_reply')" @click="edit(row.id, row.reply)" />
                        </div>
                        <Link
                            v-else-if="row.edit_url"
                            :href="row.edit_url"
                            class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-md border border-line-strong bg-card px-2.5 text-xs font-semibold text-foreground hover:bg-surface-2"
                        >
                            <ExternalLink class="size-3.5" aria-hidden="true" />{{ t('settings.bot_replies.open_editor') }}
                        </Link>
                    </div>

                    <template v-if="editingId === row.id">
                        <textarea
                            v-model="draft"
                            dir="auto"
                            rows="5"
                            maxlength="5000"
                            :aria-label="t('settings.bot_replies.reply')"
                            class="w-full rounded-md border border-line-strong bg-card px-3 py-2 text-sm focus-visible:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40"
                        />
                        <p v-if="row.source === 'text' && row.key.includes('⟦')" class="text-xs text-fg-3">
                            {{ t('settings.bot_replies.values_hint') }}
                        </p>
                        <p v-if="error" role="alert" class="text-13 text-danger">{{ error }}</p>
                        <div class="flex gap-2">
                            <Button
                                type="button"
                                size="sm"
                                :loading="busy"
                                @click="save(row.entry_id, row.reply, row.source === 'text' ? row.raw : undefined)"
                            >
                                {{ t('settings.bot_replies.save') }}
                            </Button>
                            <Button type="button" size="sm" variant="outline" :disabled="busy" @click="editingId = null">{{
                                t('settings.bot_replies.cancel')
                            }}</Button>
                        </div>
                    </template>
                    <template v-else>
                        <p class="whitespace-pre-line break-words text-[13.5px] leading-[1.75] text-foreground" dir="auto">
                            <template v-for="(part, i) in replyParts(row.reply)" :key="i"
                                ><bdi v-if="part.token" dir="ltr" class="rounded-sm bg-sunken px-1 text-xs text-fg-2">{{ part.text }}</bdi
                                ><template v-else>{{ part.text }}</template></template
                            >
                        </p>
                        <p v-if="row.original" class="text-xs text-fg-3" dir="auto">{{ t('settings.bot_replies.original') }}: {{ row.original }}</p>
                        <p v-if="row.buttons.length" class="flex flex-wrap gap-1" :aria-label="t('settings.bot_replies.buttons')">
                            <span
                                v-for="(b, i) in row.buttons"
                                :key="i"
                                class="rounded-full border border-line-strong px-2 py-0.5 text-xs text-fg-2"
                                dir="auto"
                                >{{ b }}</span
                            >
                        </p>
                    </template>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
