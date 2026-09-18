<script setup lang="ts">
import EmptyState from '@/components/crm/EmptyState.vue';
import FormDialog from '@/components/crm/FormDialog.vue';
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import { useToast } from '@/composables/useToast';
import { formatDateTime } from '@/lib/format';
import type { FlowVersionRow } from '@/types/flows';
import { AxiosError } from 'axios';
import { ArchiveRestore, CalendarClock, History, Send, UserRound } from 'lucide-vue-next';
import { ref } from 'vue';

withDefaults(
    defineProps<{
        flowId: number;
        versions: FlowVersionRow[];
        /** shows the publish shortcut when the page would allow publishing */
        canPublish?: boolean;
    }>(),
    { canPublish: false },
);

const emit = defineEmits<{ changed: []; publish: [] }>();

const { t, locale } = useI18n();
const api = useApi();
const toast = useToast();

const target = ref<FlowVersionRow | null>(null);
const open = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);

function askRestore(row: FlowVersionRow): void {
    target.value = row;
    error.value = null;
    open.value = true;
}

async function restore(): Promise<void> {
    const row = target.value;
    if (!row || busy.value) return;
    busy.value = true;
    error.value = null;
    try {
        await api.post(`/settings/bot-flow-versions/${row.id}/restore`);
        open.value = false;
        toast.push(t('flows.restored'));
        emit('changed');
    } catch (e) {
        // A 422 carries a list of validator errors, which apiErrorMessage would read as a field map.
        if (e instanceof AxiosError && e.response?.status === 422 && typeof e.response.data?.message === 'string') {
            error.value = e.response.data.message as string;
        } else {
            error.value = apiErrorMessage(e, t('flows.restore_failed'));
        }
    } finally {
        busy.value = false;
    }
}

const BADGES: Record<string, { label: string; tone: string }> = {
    published: { label: 'flows.status_published', tone: 'bg-emerald-500/15 text-emerald-800 dark:text-emerald-200' },
    draft: { label: 'flows.status_draft', tone: 'bg-amber-500/15 text-amber-800 dark:text-amber-200' },
    archived: { label: 'flows.status_archived', tone: 'bg-muted text-muted-foreground' },
};
const badge = (status: string) => BADGES[status] ?? { label: status, tone: 'bg-muted text-muted-foreground' };
const badgeLabel = (status: string): string => (BADGES[status] ? t(BADGES[status].label) : status);

const stamp = (row: FlowVersionRow): string => formatDateTime(row.published_at ?? row.created_at, locale.value);
</script>

<template>
    <div class="space-y-3 p-3">
        <div class="flex items-start gap-2">
            <p class="flex-1 text-2xs leading-relaxed text-muted-foreground">{{ t('flows.versions_hint') }}</p>
            <button
                v-if="canPublish"
                type="button"
                class="inline-flex h-7 shrink-0 items-center gap-1 rounded-md bg-primary px-2.5 text-2xs font-semibold text-primary-foreground hover:bg-primary-hover"
                @click="emit('publish')"
            >
                <Send class="size-3.5" aria-hidden="true" />{{ t('flows.publish') }}
            </button>
        </div>

        <EmptyState v-if="!versions.length" :icon="History" :title="t('flows.no_versions')" :body="t('flows.no_versions_body')" class="p-4" />

        <ol v-else class="space-y-2">
            <li
                v-for="row in versions"
                :key="row.id"
                class="rounded-lg border bg-card p-3 transition-colors"
                :class="row.status === 'published' ? 'border-emerald-500/40 ring-1 ring-emerald-500/20' : 'border-border'"
            >
                <div class="flex items-center gap-2">
                    <span class="text-sm font-bold tabular-nums">{{ t('flows.version', { n: row.version }) }}</span>
                    <span class="rounded-full px-2 py-0.5 text-2xs font-semibold" :class="badge(row.status).tone">{{ badgeLabel(row.status) }}</span>
                    <button
                        v-if="row.status === 'archived'"
                        type="button"
                        class="ms-auto inline-flex h-7 items-center gap-1 rounded-md border border-input bg-card px-2 text-2xs font-medium hover:bg-muted"
                        @click="askRestore(row)"
                    >
                        <ArchiveRestore class="size-3.5" aria-hidden="true" />{{ t('flows.restore') }}
                    </button>
                </div>
                <p v-if="row.note" dir="auto" class="mt-1.5 whitespace-pre-line break-words text-xs text-foreground/90">{{ row.note }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-2xs text-muted-foreground">
                    <span class="inline-flex items-center gap-1">
                        <UserRound class="size-3" aria-hidden="true" />{{ row.created_by ?? t('flows.version_unknown_author') }}
                    </span>
                    <span v-if="row.published_at || row.created_at" class="inline-flex items-center gap-1">
                        <CalendarClock class="size-3" aria-hidden="true" />{{ stamp(row) }}
                    </span>
                </div>
            </li>
        </ol>

        <FormDialog
            v-model:open="open"
            :title="t('flows.restore_title', { n: target?.version ?? '' })"
            :description="t('flows.restore_confirm', { n: target?.version ?? '' })"
            :busy="busy"
            :error="error"
            :submit-label="t('flows.restore')"
            @submit="restore"
        />
    </div>
</template>
