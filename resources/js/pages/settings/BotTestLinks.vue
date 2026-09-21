<script setup lang="ts">
/**
 * Settings → «روابط التجربة» (design 2026-09-21 §1). One card per link: the address to
 * share, how often it was opened, how many runs it produced, and the runs themselves.
 */
import FormDialog from '@/components/crm/FormDialog.vue';
import PageHeader from '@/components/crm/PageHeader.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCount, formatShortDuration } from '@/lib/format';
import type { TestLinkRow, TestLinkSessionRow } from '@/types/admin';
import { Head, router } from '@inertiajs/vue3';
import { BarChart3, Check, Copy, ExternalLink, Pencil, Play, Plus, Square, Trash2 } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{ links: TestLinkRow[] }>();

const { t, locale } = useI18n();
const toast = useToast();
const api = useApi();

const open = ref(false);
const editingId = ref<number | null>(null);
const busy = ref(false);
const error = ref<string | null>(null);
const copiedId = ref<number | null>(null);
const openSessions = ref<number | null>(null);
const sessions = ref<TestLinkSessionRow[]>([]);
const loadingSessions = ref(false);

const form = reactive({ label: '', expires_at: '', max_sessions: '', max_messages_per_session: '60' });

const reload = () => router.reload({ only: ['links'] });

function edit(row: TestLinkRow | null): void {
    editingId.value = row?.id ?? null;
    Object.assign(form, {
        label: row?.label ?? '',
        expires_at: row?.expires_at ? row.expires_at.slice(0, 10) : '',
        max_sessions: row?.max_sessions ? String(row.max_sessions) : '',
        max_messages_per_session: String(row?.max_messages_per_session ?? 60),
    });
    error.value = null;
    open.value = true;
}

async function submit(): Promise<void> {
    busy.value = true;
    error.value = null;
    const payload = {
        label: form.label,
        expires_at: form.expires_at || null,
        max_sessions: form.max_sessions ? Number(form.max_sessions) : null,
        max_messages_per_session: Number(form.max_messages_per_session) || 60,
    };

    try {
        if (editingId.value === null) await api.post('/settings/bot-test-links', payload);
        else await api.patch(`/settings/bot-test-links/${editingId.value}`, payload);
        toast.push(t('ui.saved'));
        open.value = false;
        reload();
    } catch (e) {
        error.value = apiErrorMessage(e, t('common.error'));
    } finally {
        busy.value = false;
    }
}

async function toggle(row: TestLinkRow): Promise<void> {
    if (row.is_active && !window.confirm(t('settings.test_links.confirm_stop', { name: row.label }))) return;

    try {
        await api.patch(`/settings/bot-test-links/${row.id}`, { is_active: !row.is_active });
        reload();
    } catch (e) {
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    }
}

async function remove(row: TestLinkRow): Promise<void> {
    if (!window.confirm(t('settings.test_links.confirm_delete', { name: row.label }))) return;

    try {
        await api.delete(`/settings/bot-test-links/${row.id}`);
        toast.push(t('settings.test_links.deleted'));
        if (openSessions.value === row.id) openSessions.value = null;
        reload();
    } catch (e) {
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    }
}

async function copy(row: TestLinkRow): Promise<void> {
    try {
        await navigator.clipboard.writeText(row.url);
        copiedId.value = row.id;
        window.setTimeout(() => (copiedId.value = null), 1600);
    } catch {
        toast.push(row.url);
    }
}

async function showSessions(row: TestLinkRow): Promise<void> {
    if (openSessions.value === row.id) {
        openSessions.value = null;
        return;
    }

    openSessions.value = row.id;
    loadingSessions.value = true;
    sessions.value = [];

    try {
        const { data } = await api.get(`/settings/bot-test-links/${row.id}/sessions`);
        sessions.value = data.data.sessions;
    } catch (e) {
        toast.push(apiErrorMessage(e, t('common.error')), 'error');
    } finally {
        loadingSessions.value = false;
    }
}

const stamp = (value: string | null): string =>
    value ? new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }) : '—';

const minutes = (seconds: number): string => formatShortDuration(seconds, locale.value);

function status(row: TestLinkRow): { label: string; tone: string } {
    if (!row.is_active) return { label: t('settings.test_links.stopped'), tone: 'bg-muted text-muted-foreground' };
    if (!row.is_open) return { label: t('settings.test_links.expired'), tone: 'bg-amber-500/10 text-amber-600' };
    return { label: t('settings.test_links.active'), tone: 'bg-emerald-500/10 text-emerald-600' };
}

const breadcrumbs = computed(() => [{ title: t('settings.test_links.title'), href: '/settings/bot-test-links' }]);
const input = 'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';
const iconBtn = 'rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground';
</script>

<template>
    <Head :title="t('settings.test_links.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-3xl space-y-5 p-3 md:p-6">
            <PageHeader :title="t('settings.test_links.title')" :description="t('settings.test_links.description')">
                <a href="/reports/team-test" class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-3 text-xs font-medium hover:bg-muted">
                    <BarChart3 class="size-3.5" aria-hidden="true" />{{ t('settings.test_links.report') }}
                </a>
                <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground" @click="edit(null)">
                    <Plus class="size-3.5" aria-hidden="true" />{{ t('settings.test_links.add') }}
                </button>
            </PageHeader>

            <p v-if="!props.links.length" class="rounded-lg bg-card p-6 text-center text-sm text-muted-foreground shadow-card">
                {{ t('settings.test_links.empty') }}
            </p>

            <article v-for="row in props.links" :key="row.id" class="space-y-3 rounded-lg bg-card p-4 shadow-card">
                <header class="flex flex-wrap items-start gap-x-3 gap-y-1">
                    <div class="min-w-0 flex-1">
                        <h2 class="flex items-center gap-2 truncate text-sm font-semibold" dir="auto">
                            {{ row.label }}
                            <span class="rounded-full px-2 py-0.5 text-2xs font-medium" :class="status(row).tone">{{ status(row).label }}</span>
                        </h2>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ t('settings.test_links.stats', { views: row.views_count, runs: row.runs_count }) }}
                            ·
                            {{ row.last_opened_at ? `${t('settings.test_links.last_opened')} ${stamp(row.last_opened_at)}` : t('settings.test_links.never_opened') }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-0.5">
                        <button type="button" :class="iconBtn" :title="t('ui.edit')" :aria-label="`${t('ui.edit')} ${row.label}`" @click="edit(row)">
                            <Pencil class="size-3.5" />
                        </button>
                        <button
                            type="button"
                            :class="iconBtn"
                            :title="row.is_active ? t('settings.test_links.stop') : t('settings.test_links.activate')"
                            :aria-label="row.is_active ? t('settings.test_links.stop') : t('settings.test_links.activate')"
                            @click="toggle(row)"
                        >
                            <Square v-if="row.is_active" class="size-3.5" />
                            <Play v-else class="size-3.5" />
                        </button>
                        <button
                            type="button"
                            :class="[iconBtn, 'hover:text-destructive']"
                            :title="t('ui.delete')"
                            :aria-label="`${t('ui.delete')} ${row.label}`"
                            @click="remove(row)"
                        >
                            <Trash2 class="size-3.5" />
                        </button>
                    </div>
                </header>

                <div class="flex flex-wrap items-center gap-2 rounded-md bg-muted/60 px-3 py-2">
                    <code class="min-w-0 flex-1 truncate text-xs" dir="ltr">{{ row.url }}</code>
                    <button type="button" class="inline-flex h-7 items-center gap-1 rounded-md bg-background px-2 text-2xs font-medium shadow-sm" @click="copy(row)">
                        <Check v-if="copiedId === row.id" class="size-3 text-emerald-600" aria-hidden="true" />
                        <Copy v-else class="size-3" aria-hidden="true" />
                        {{ copiedId === row.id ? t('settings.test_links.copied') : t('settings.test_links.copy') }}
                    </button>
                    <a
                        :href="row.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex h-7 items-center gap-1 rounded-md bg-background px-2 text-2xs font-medium shadow-sm"
                    >
                        <ExternalLink class="size-3" aria-hidden="true" />{{ t('settings.test_links.open') }}
                    </a>
                </div>

                <p class="text-2xs text-muted-foreground">
                    {{ t('settings.test_links.max_messages') }}: {{ formatCount(row.max_messages_per_session, locale) }} ·
                    {{ t('settings.test_links.max_sessions') }}: {{ row.max_sessions === null ? t('settings.test_links.unlimited') : formatCount(row.max_sessions, locale) }}
                    <template v-if="row.expires_at"> · {{ t('settings.test_links.expires_at') }} {{ stamp(row.expires_at) }}</template>
                </p>

                <button
                    type="button"
                    class="text-xs font-medium text-primary hover:underline"
                    :aria-expanded="openSessions === row.id"
                    @click="showSessions(row)"
                >
                    {{ t('settings.test_links.sessions') }} ({{ formatCount(row.runs_count, locale) }})
                </button>

                <div v-if="openSessions === row.id" class="rounded-md border border-border/60">
                    <p v-if="loadingSessions" class="p-3 text-xs text-muted-foreground">…</p>
                    <p v-else-if="!sessions.length" class="p-3 text-xs text-muted-foreground">{{ t('settings.test_links.sessions_empty') }}</p>
                    <ul v-else class="divide-y divide-border/60">
                        <li v-for="s in sessions" :key="s.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-xs">
                            <span class="font-medium" dir="auto">{{ s.label }}</span>
                            <span class="text-muted-foreground">{{ stamp(s.started_at) }}</span>
                            <span class="text-muted-foreground">{{ minutes(s.duration_seconds) }}</span>
                            <span class="text-muted-foreground">{{ formatCount(s.messages_count, locale) }} · {{ s.device_family ?? '—' }}</span>
                            <span class="rounded-full px-2 py-0.5 text-2xs" :class="s.ended_at ? 'bg-muted text-muted-foreground' : 'bg-emerald-500/10 text-emerald-600'">
                                {{ s.ended_at ? t('settings.test_links.session_ended') : t('settings.test_links.session_running') }}
                            </span>
                            <a v-if="s.conversation_id" :href="`/inbox?c=${s.conversation_id}`" class="ms-auto text-primary hover:underline">
                                {{ t('settings.test_links.open_conversation') }}
                            </a>
                        </li>
                    </ul>
                </div>
            </article>
        </div>

        <FormDialog
            v-model:open="open"
            :title="editingId ? t('settings.test_links.edit') : t('settings.test_links.add')"
            :busy="busy"
            :error="error"
            @submit="submit"
        >
            <label class="grid gap-1">
                <span class="text-xs font-medium">{{ t('settings.test_links.label') }}</span>
                <input v-model="form.label" :class="input" :placeholder="t('settings.test_links.label_placeholder')" required maxlength="120" dir="auto" />
            </label>
            <label class="grid gap-1">
                <span class="text-xs font-medium">{{ t('settings.test_links.expires_at') }}</span>
                <input v-model="form.expires_at" type="date" :class="input" />
                <span class="text-2xs text-muted-foreground">{{ t('settings.test_links.expires_hint') }}</span>
            </label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1">
                    <span class="text-xs font-medium">{{ t('settings.test_links.max_sessions') }}</span>
                    <input v-model="form.max_sessions" type="number" min="1" max="1000" :class="input" :placeholder="t('settings.test_links.unlimited')" />
                </label>
                <label class="grid gap-1">
                    <span class="text-xs font-medium">{{ t('settings.test_links.max_messages') }}</span>
                    <input v-model="form.max_messages_per_session" type="number" min="5" max="500" :class="input" />
                </label>
            </div>
        </FormDialog>
    </AppLayout>
</template>
