<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { formatClock } from '@/lib/format';
import type { Message, Note } from '@/types/crm';
import { AlertCircle, Check, CheckCheck, Clock3, RotateCw, StickyNote } from 'lucide-vue-next';
import { computed, type Component } from 'vue';

const props = defineProps<{ message?: Message; note?: Note; platformColor: string; retrying?: boolean }>();
const emit = defineEmits<{ retry: [message: Message] }>();

const { t, locale } = useI18n();

type Kind = 'customer' | 'user' | 'bot' | 'system' | 'note';

const kind = computed<Kind>(() => {
    if (props.note || !props.message) return 'note';
    const m = props.message;
    if (m.sender_type === 'system') return 'system';
    if (m.direction === 'in') return 'customer';
    return m.sender_type === 'bot' ? 'bot' : 'user';
});

const body = computed(() => props.note?.body ?? props.message?.body ?? '');
const stamp = computed(() => formatClock(props.note?.created_at ?? props.message?.created_at, locale.value));
const outbound = computed(() => kind.value === 'user' || kind.value === 'bot' || kind.value === 'note');
const failed = computed(() => props.message?.status === 'failed');

const statusIcons: Record<string, Component> = { queued: Clock3, sent: Check, delivered: CheckCheck, read: CheckCheck, failed: AlertCircle };
const statusIcon = computed(() => (props.message?.status ? statusIcons[props.message.status] : null));

const bubbleClass = computed(() => ({
    'rounded-ss-sm border border-s-[3px] bg-card': kind.value === 'customer',
    'rounded-se-sm border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800': kind.value === 'user',
    'rounded-se-sm border border-indigo-100 bg-indigo-50 text-indigo-950 dark:border-indigo-900 dark:bg-indigo-950/60 dark:text-indigo-100': kind.value === 'bot',
    'rounded-se-sm border border-dashed border-amber-300 bg-amber-50 text-amber-950': kind.value === 'note',
    '!border-red-300': failed.value,
}));
</script>

<template>
    <div v-if="kind === 'system'" class="flex justify-center py-1">
        <p class="max-w-[85%] rounded-full bg-muted px-3 py-1 text-center text-2xs text-muted-foreground">
            {{ body }} <span class="tabular-nums opacity-70">· {{ stamp }}</span>
        </p>
    </div>

    <div v-else class="flex" :class="outbound ? 'justify-end' : 'justify-start'">
        <article
            class="max-w-[min(80%,36rem)] rounded-xl px-3 py-2 text-sm shadow-sm"
            :class="bubbleClass"
            :style="kind === 'customer' ? { borderInlineStartColor: platformColor } : undefined"
        >
            <header v-if="kind !== 'customer'" class="mb-1 flex items-center gap-1.5 text-2xs font-medium">
                <span
                    v-if="kind === 'user'"
                    class="rounded px-1.5 py-px text-white"
                    :style="{ backgroundColor: message?.user?.color || '#64748b' }"
                >
                    {{ message?.user?.name }}
                </span>
                <span v-else-if="kind === 'bot'" class="text-indigo-700 dark:text-indigo-300">🤖 {{ t('thread.bot') }}</span>
                <span v-else class="inline-flex items-center gap-1 text-amber-800">
                    <StickyNote class="size-3" aria-hidden="true" />{{ t('thread.note') }}<template v-if="note?.user"> · {{ note.user.name }}</template>
                </span>
                <span v-if="message?.is_template" class="rounded bg-emerald-50 px-1 text-emerald-700">{{ t('thread.template') }}</span>
            </header>

            <p class="whitespace-pre-wrap break-words leading-relaxed" dir="auto">{{ body }}</p>

            <footer class="mt-1 flex items-center justify-end gap-1 text-2xs text-muted-foreground">
                <span class="tabular-nums">{{ stamp }}</span>
                <component
                    :is="statusIcon"
                    v-if="kind === 'user' && statusIcon"
                    class="size-3"
                    :class="{ 'text-sky-600': message?.status === 'read', 'text-red-600': failed }"
                    role="img"
                    :aria-label="t(`thread.status.${message?.status}`)"
                />
            </footer>

            <div v-if="failed && message" class="mt-1.5 flex items-center gap-2 border-t border-red-200 pt-1.5 text-2xs text-red-700">
                <span class="min-w-0 flex-1">{{ message.error || t('thread.failed') }}</span>
                <button
                    type="button"
                    class="inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 font-medium hover:bg-red-100 disabled:opacity-50"
                    :disabled="retrying"
                    @click="emit('retry', message)"
                >
                    <RotateCw class="size-3" :class="{ 'animate-spin': retrying }" aria-hidden="true" />{{ t('common.retry') }}
                </button>
            </div>
        </article>
    </div>
</template>
