<script setup lang="ts">
import { apiErrorMessage, useApi } from '@/composables/useApi';
import { useI18n } from '@/composables/useI18n';
import MessageCards from '@/components/crm/MessageCards.vue';
import type { OutboundCards } from '@/types/crm';
import type { SandboxButton, SandboxEvent, SandboxResponse, SandboxSource } from '@/types/flows';
import { AxiosError } from 'axios';
import {
    Bot,
    Camera,
    CircleAlert,
    ClipboardCheck,
    CornerDownLeft,
    Flag,
    FlaskConical,
    Headset,
    MessageCircleQuestion,
    RotateCcw,
    SendHorizontal,
    TriangleAlert,
    Workflow,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, watch, type Component } from 'vue';
import MessengerBubble, { MESSENGER_CHIP_CLASS } from './MessengerBubble.vue';

const props = defineProps<{ flowId: number; source: SandboxSource; dirty: boolean }>();

const emit = defineEmits<{
    /** The step the test conversation is on now; flowKey says which flow it belongs to (a menu can hop to another flow). */
    current: [stepId: string | null, flowKey: string | null];
    'update:source': [source: SandboxSource];
}>();

const { t } = useI18n();
const api = useApi();

type ChatItem =
    | { id: number; kind: 'bot'; text: string; buttons: SandboxButton[]; cards: OutboundCards | null }
    | { id: number; kind: 'customer'; text: string }
    | { id: number; kind: 'event'; event: SandboxEvent }
    | { id: number; kind: 'error'; text: string }
    | { id: number; kind: 'ended' };

const items = ref<ChatItem[]>([]);
const state = ref<Record<string, unknown> | null>(null);
const busy = ref(false);
const text = ref('');
const scroller = ref<HTMLElement | null>(null);
const inputEl = ref<HTMLInputElement | null>(null);

const LEADING_EVENTS = ['flow_start', 'exit'];

let nextId = 1;
/** Bumped on every reset so a reply for an earlier conversation is dropped. */
let session = 0;

type NewItem = ChatItem extends infer I ? (I extends ChatItem ? Omit<I, 'id'> : never) : never;

function push(item: NewItem): void {
    items.value.push({ ...item, id: nextId++ } as ChatItem);
}

/** `refocus` puts the caret back after a typed message (never on load, so phones don't pop the keyboard). */
async function run(input: { text?: string; payload?: string; photo?: boolean }, refocus = false): Promise<void> {
    const mine = session;
    busy.value = true;
    scrollDown();
    try {
        const { data } = await api.post<SandboxResponse>(`/settings/bot-flows/${props.flowId}/simulate`, {
            state: state.value,
            input,
            source: props.source,
        });
        if (mine !== session) return;
        // Moving between flows reads best above the new prompt; cases and handovers after what the bot said.
        const leading = data.events.filter((event) => LEADING_EVENTS.includes(event.type));
        for (const event of leading) push({ kind: 'event', event });
        for (const message of data.messages) push({ kind: 'bot', text: message.text, buttons: message.buttons ?? [], cards: message.cards ?? null });
        for (const event of data.events) if (!leading.includes(event)) push({ kind: 'event', event });
        state.value = data.state;
        if (!data.current && !data.state) push({ kind: 'ended' });
        emit('current', data.current?.step_id ?? null, data.current?.flow_key ?? null);
    } catch (e) {
        if (mine !== session) return;
        const message =
            e instanceof AxiosError && e.response?.status === 422 && typeof e.response.data?.message === 'string'
                ? (e.response.data.message as string)
                : apiErrorMessage(e, t('flows.test_failed'));
        push({ kind: 'error', text: message });
    } finally {
        if (mine === session) {
            busy.value = false;
            scrollDown();
            if (refocus) nextTick(() => inputEl.value?.focus({ preventScroll: true }));
        }
    }
}

function reset(): void {
    session++;
    items.value = [];
    state.value = null;
    text.value = '';
    busy.value = false;
    emit('current', null, null);
    run({});
}

function sendText(): void {
    const value = text.value.trim();
    if (!value || busy.value) return;
    text.value = '';
    push({ kind: 'customer', text: value });
    run({ text: value }, true);
}

function tap(button: SandboxButton): void {
    if (busy.value) return;
    push({ kind: 'customer', text: button.title });
    // Buttons must travel as payloads: the sandbox does not map typed numbers to buttons.
    run({ payload: button.payload });
}

function sendPhoto(): void {
    if (busy.value) return;
    push({ kind: 'customer', text: t('flows.test_photo_sent') });
    run({ photo: true });
}

/** Quick replies show only under the newest bot message, and only until the customer answers. */
const chipsFor = computed<number | null>(() => {
    if (busy.value) return null;
    for (let i = items.value.length - 1; i >= 0; i--) {
        const item = items.value[i];
        if (item.kind === 'customer') return null;
        if (item.kind === 'bot') return item.buttons.length ? item.id : null;
    }
    return null;
});

/** A bot bubble directly after another bot bubble hides its avatar, like Messenger groups. */
function showAvatar(index: number): boolean {
    const next = items.value[index + 1];
    return !next || next.kind !== 'bot';
}

function scrollDown(): void {
    nextTick(() => {
        const el = scroller.value;
        if (el) el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' });
    });
}

watch(() => items.value.length, scrollDown);

const EVENT_ICONS: Record<string, Component> = {
    case: ClipboardCheck,
    handover: Headset,
    flow_start: Workflow,
    exit: CornerDownLeft,
    question: MessageCircleQuestion,
    invalid: TriangleAlert,
};
const eventIcon = (type: string): Component => EVENT_ICONS[type] ?? Flag;

function eventTone(type: string): string {
    if (type === 'invalid') return 'bg-amber-500/15 text-amber-800 dark:text-amber-200';
    if (type === 'case') return 'bg-emerald-500/10 text-emerald-800 dark:text-emerald-200';
    if (type === 'handover') return 'bg-sky-500/10 text-sky-800 dark:text-sky-200';
    return 'bg-muted text-muted-foreground';
}

function setSource(source: SandboxSource): void {
    if (source !== props.source) emit('update:source', source);
}

watch(
    () => [props.flowId, props.source],
    () => reset(),
);

onMounted(reset);

defineExpose({ reset });
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <!-- Toolbar: source toggle + start over -->
        <div class="flex items-center gap-2 border-b border-border px-3 py-2">
            <span class="text-2xs text-muted-foreground">{{ t('flows.test_source') }}</span>
            <div class="inline-flex rounded-md bg-muted p-0.5 text-2xs font-semibold" role="radiogroup" :aria-label="t('flows.test_source')">
                <button
                    v-for="option in ['draft', 'published'] as const"
                    :key="option"
                    type="button"
                    role="radio"
                    :aria-checked="source === option"
                    class="rounded px-2.5 py-1 transition-colors"
                    :class="source === option ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                    @click="setSource(option)"
                >
                    {{ option === 'draft' ? t('flows.test_source_draft') : t('flows.test_source_published') }}
                </button>
            </div>
            <button
                type="button"
                class="ms-auto inline-flex h-7 items-center gap-1 rounded-md border border-input bg-card px-2 text-2xs font-medium hover:bg-muted"
                @click="reset"
            >
                <RotateCcw class="size-3.5" aria-hidden="true" />{{ t('flows.test_reset') }}
            </button>
        </div>

        <p
            v-if="dirty && source === 'draft'"
            role="status"
            class="flex items-center gap-1.5 border-b border-amber-500/20 bg-amber-500/10 px-3 py-1.5 text-2xs font-medium text-amber-800 dark:text-amber-200"
        >
            <TriangleAlert class="size-3.5 shrink-0" aria-hidden="true" />{{ t('flows.test_unsaved') }}
        </p>

        <!-- Conversation -->
        <div ref="scroller" class="scrollbar-thin min-h-0 flex-1 space-y-2 overflow-y-auto bg-muted/30 px-3 py-3" aria-live="polite">
            <p class="mx-auto flex w-fit items-center gap-1.5 rounded-full px-2 py-0.5 text-center text-2xs text-muted-foreground">
                <FlaskConical class="size-3" aria-hidden="true" />{{ t('flows.test_intro') }}
            </p>

            <template v-for="(item, index) in items" :key="item.id">
                <!-- Bot: start side (right in RTL); customer: end side -->
                <MessengerBubble
                    v-if="item.kind === 'bot'"
                    :text="item.cards?.type === 'generic' ? '' : item.text"
                    :show-avatar="showAvatar(index)"
                >
                    <!-- Branch cards / the store link, as Messenger shows them (a carousel's text is only its fallback). -->
                    <MessageCards v-if="item.cards" :cards="item.cards" variant="messenger" class="max-w-full" />
                    <div v-if="chipsFor === item.id" class="flex flex-wrap gap-1.5">
                        <button
                            v-for="(button, b) in item.buttons"
                            :key="b"
                            type="button"
                            dir="auto"
                            :class="[MESSENGER_CHIP_CLASS, 'hover:bg-primary hover:text-primary-foreground']"
                            @click="tap(button)"
                        >
                            {{ button.title }}
                        </button>
                    </div>
                </MessengerBubble>

                <MessengerBubble v-else-if="item.kind === 'customer'" side="customer" :text="item.text" />

                <!-- Engine events: centred pills -->
                <div v-else-if="item.kind === 'event'" class="flex justify-center py-0.5">
                    <span
                        class="inline-flex max-w-[92%] items-center gap-1.5 rounded-full px-3 py-1 text-center text-2xs font-medium"
                        :class="eventTone(item.event.type)"
                    >
                        <component :is="eventIcon(item.event.type)" class="size-3.5 shrink-0" aria-hidden="true" />
                        <span dir="auto">{{ item.event.label }}</span>
                    </span>
                </div>

                <div v-else-if="item.kind === 'error'" class="flex justify-center py-0.5" role="alert">
                    <span
                        class="inline-flex max-w-[92%] items-center gap-1.5 rounded-full bg-destructive/10 px-3 py-1 text-center text-2xs font-medium text-destructive"
                    >
                        <CircleAlert class="size-3.5 shrink-0" aria-hidden="true" />{{ item.text }}
                    </span>
                </div>

                <div v-else class="flex justify-center py-1">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full border border-dashed border-border bg-card px-3 py-1 text-2xs font-medium text-muted-foreground"
                    >
                        <Flag class="size-3.5 shrink-0" aria-hidden="true" />{{ t('flows.test_ended') }}
                    </span>
                </div>
            </template>

            <div v-if="busy" class="flex items-end gap-1.5" :aria-label="t('flows.test_typing')" role="status">
                <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                    <Bot class="size-3.5" aria-hidden="true" />
                </span>
                <span class="flex items-center gap-1 rounded-2xl rounded-es-md bg-card px-3 py-2.5 shadow-sm ring-1 ring-border">
                    <span
                        v-for="n in 3"
                        :key="n"
                        class="typing-dot size-1.5 rounded-full bg-muted-foreground/70"
                        :style="{ animationDelay: `${(n - 1) * 150}ms` }"
                    />
                </span>
            </div>
        </div>

        <!-- Composer -->
        <form class="flex items-center gap-1.5 border-t border-border bg-card p-2" @submit.prevent="sendText">
            <button
                type="button"
                class="inline-flex h-9 shrink-0 items-center gap-1 rounded-full border border-input px-2.5 text-xs font-medium hover:bg-muted disabled:opacity-50"
                :disabled="busy"
                :aria-label="t('flows.test_photo')"
                @click="sendPhoto"
            >
                <Camera class="size-4 sm:hidden" aria-hidden="true" /><span class="hidden sm:inline">{{ t('flows.test_photo') }}</span>
            </button>
            <input
                ref="inputEl"
                v-model="text"
                dir="auto"
                maxlength="1000"
                class="h-9 min-w-0 flex-1 rounded-full border border-input bg-background px-3.5 text-sm"
                :placeholder="t('flows.test_placeholder')"
                :aria-label="t('flows.test_placeholder')"
            />
            <button
                type="submit"
                class="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground transition-opacity disabled:opacity-40"
                :disabled="busy || !text.trim()"
                :aria-label="t('flows.test_send')"
            >
                <SendHorizontal class="size-4 rtl:-scale-x-100" aria-hidden="true" />
            </button>
        </form>
    </div>
</template>

<style scoped>
.typing-dot {
    animation: typing-bounce 1s infinite ease-in-out;
}
@keyframes typing-bounce {
    0%,
    60%,
    100% {
        transform: translateY(0);
        opacity: 0.5;
    }
    30% {
        transform: translateY(-3px);
        opacity: 1;
    }
}
</style>
