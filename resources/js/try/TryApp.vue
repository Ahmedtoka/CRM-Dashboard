<script setup lang="ts">
/**
 * The tester's chat on a public test link (design 2026-09-21 §2, owner addition 4).
 *
 * Everything the tester reads here is English — the page belongs to the CRM, not to
 * the shop. The bot answers in Arabic because its words come from the published
 * flows, so every bubble is `dir="auto"` and lays itself out per message.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { TryCardButton, TryCards, TryMessage, TryState } from './types';

const props = defineProps<{ token: string; initial: TryState }>();

const state = ref<TryState>(props.initial);
const name = ref('');
const draft = ref('');
const busy = ref(false);
const error = ref<string | null>(null);
const thread = ref<HTMLElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const cameraInput = ref<HTMLInputElement | null>(null);

let timer: number | undefined;

const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
const base = `/try/${props.token}`;

const session = computed(() => state.value.session);
const messages = computed(() => state.value.messages);
const closed = computed(() => !state.value.open);
const finished = computed(() => session.value?.ended === true);
const capReached = computed(() => session.value?.cap_reached === true);
const canWrite = computed(() => state.value.started && !closed.value && !finished.value && !capReached.value);
const initials = computed(() => (state.value.label || 'Test').trim().charAt(0).toUpperCase());

/** The buttons of the newest bot message, shown as pills under the thread. */
const quickReplies = computed(() => {
    const last = messages.value[messages.value.length - 1];
    return last && last.direction === 'out' ? last.buttons : [];
});

/** The tester's own last message carries the tick line, as it does on Messenger. */
const receiptId = computed(() => {
    for (let i = messages.value.length - 1; i >= 0; i--) {
        if (messages.value[i].direction === 'in') return messages.value[i].id;
    }
    return null;
});

/** Messenger's bubble grouping: a run of messages from one side shares tightened corners. */
type Position = 'solo' | 'first' | 'mid' | 'last';

const rows = computed(() =>
    messages.value.map((m, i) => {
        const prev = messages.value[i - 1];
        const next = messages.value[i + 1];
        const sameAs = (other?: TryMessage) => !!other && other.direction === m.direction && withinRun(other, m);
        const withPrev = sameAs(prev);
        const withNext = sameAs(next);
        const position: Position = withPrev && withNext ? 'mid' : withPrev ? 'last' : withNext ? 'first' : 'solo';

        return {
            message: m,
            mine: m.direction === 'in',
            position,
            gap: i > 0 && !withPrev,
            agent: m.sender === 'user' && !withPrev,
        };
    }),
);

function withinRun(a: TryMessage, b: TryMessage): boolean {
    if (!a.created_at || !b.created_at) return true;
    return Math.abs(Date.parse(b.created_at) - Date.parse(a.created_at)) < 5 * 60 * 1000;
}

function href(b: TryCardButton): string | undefined {
    return b.type === 'phone' ? (b.phone ? `tel:${b.phone}` : undefined) : b.url;
}

function generic(cards: TryCards) {
    return cards.type === 'generic' ? cards.cards : [];
}

function links(cards: TryCards) {
    return cards.type === 'button' ? cards.buttons : [];
}

async function call(path: string, body?: BodyInit, headers: Record<string, string> = {}): Promise<boolean> {
    busy.value = true;
    error.value = null;
    try {
        const response = await fetch(`${base}${path}`, {
            method: body === undefined ? 'GET' : 'POST',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf, ...headers },
            body,
        });
        const payload = (await response.json().catch(() => null)) as (TryState & { message?: string }) | null;

        if (payload && typeof payload.open === 'boolean') state.value = payload;

        if (!response.ok) {
            error.value =
                response.status === 429
                    ? 'That was a lot at once — give it a moment.'
                    : (payload?.message ?? 'Something went wrong. Try again.');
            return false;
        }
        return true;
    } catch {
        error.value = 'No connection. Check your internet and try again.';
        return false;
    } finally {
        busy.value = false;
    }
}

function form(fields: Record<string, string>): FormData {
    const data = new FormData();
    Object.entries(fields).forEach(([key, value]) => data.append(key, value));
    return data;
}

async function startChat(): Promise<void> {
    const value = name.value.trim();
    if (value.length < state.value.name_min) {
        error.value = `Please use at least ${state.value.name_min} letters.`;
        return;
    }
    await call('/start', form({ name: value }));
    scrollDown();
}

async function send(text: string, payload?: string): Promise<void> {
    if (!canWrite.value || busy.value) return;
    const fields: Record<string, string> = { text, session: session.value?.token ?? '' };
    if (payload) fields.payload = payload;
    draft.value = '';
    if (await call('/messages', form(fields))) scrollDown();
}

async function sendPhoto(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file || !canWrite.value) return;

    const data = new FormData();
    data.append('photo', file);
    data.append('session', session.value?.token ?? '');
    if (await call('/photo', data)) scrollDown();
}

async function poll(): Promise<void> {
    if (busy.value) return;
    await call('/state');
}

async function startOver(): Promise<void> {
    if (!window.confirm('Start over? This chat will be saved for the team and a brand-new one begins.')) return;
    if (await call('/reset', form({ session: session.value?.token ?? '' }))) scrollDown();
}

function onComposerKey(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        const text = draft.value.trim();
        if (text) send(text);
    }
}

function scrollDown(): void {
    nextTick(() => {
        const el = thread.value;
        if (el) el.scrollTop = el.scrollHeight;
    });
}

function grow(event: Event): void {
    const el = event.target as HTMLTextAreaElement;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 96)}px`;
}

watch(() => messages.value.length, scrollDown);

onMounted(() => {
    scrollDown();
    timer = window.setInterval(poll, state.value.poll_ms || 3000);
});

onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div class="try-shell">
        <header class="try-bar">
            <span class="try-avatar" aria-hidden="true">{{ initials }}</span>
            <div class="try-bar-text">
                <h1 class="try-bar-name">{{ state.label }}</h1>
                <p class="try-bar-sub">
                    <span class="try-chip">Test chat</span>
                    <template v-if="session"> &nbsp;{{ session.name }}<span v-if="session.run_no > 1"> · run {{ session.run_no }}</span></template>
                </p>
            </div>
            <button v-if="state.started" type="button" class="try-bar-action" :disabled="busy || closed" @click="startOver">Start over</button>
        </header>

        <!-- The link is finished: nothing to chat with, so say so plainly. -->
        <div v-if="closed" class="try-centre">
            <h1>This chat is closed</h1>
            <p>The link has been turned off or has expired. Ask whoever sent it for a new one.</p>
        </div>

        <!-- «مين معانا؟» — asked in English, because the page is ours, not the shop's. -->
        <div v-else-if="!state.started" class="try-centre">
            <h1>Who's testing today?</h1>
            <p>Type your name, then chat exactly as a customer would. Everything you send is saved for the team to review.</p>
            <form @submit.prevent="startChat">
                <input
                    v-model="name"
                    type="text"
                    autocomplete="given-name"
                    :maxlength="state.name_max"
                    :placeholder="`Your name`"
                    aria-label="Your name"
                    enterkeyhint="go"
                />
                <button type="submit" class="try-primary" :disabled="busy">Start chatting</button>
                <p v-if="error" class="try-error" role="alert">{{ error }}</p>
            </form>
        </div>

        <template v-else>
            <div ref="thread" class="try-thread">
                <template v-for="row in rows" :key="row.message.id">
                    <p v-if="row.agent" class="try-agent">Someone from the team joined</p>

                    <div class="try-row" :class="[row.mine ? 'out' : 'in', row.position, { gap: row.gap }]">
                        <div v-if="row.message.images.length" class="try-bubble photo">
                            <img v-for="image in row.message.images" :key="image.id" :src="image.url" alt="" loading="lazy" />
                        </div>
                        <div v-else-if="row.message.body" class="try-bubble" dir="auto">{{ row.message.body }}</div>
                    </div>

                    <div v-if="row.message.body && row.message.images.length" class="try-row" :class="[row.mine ? 'out' : 'in', 'last']">
                        <div class="try-bubble" dir="auto">{{ row.message.body }}</div>
                    </div>

                    <!-- Generic-template carousel and link buttons, drawn the way Messenger draws them. -->
                    <div v-if="row.message.cards && generic(row.message.cards).length" class="try-cards" role="list">
                        <article v-for="(card, i) in generic(row.message.cards)" :key="i" class="try-card" role="listitem">
                            <div class="try-card-body">
                                <p class="try-card-title" dir="auto">{{ card.title }}</p>
                                <p v-if="card.subtitle" class="try-card-sub" dir="auto">{{ card.subtitle }}</p>
                            </div>
                            <a
                                v-for="(button, j) in card.buttons"
                                :key="j"
                                class="try-card-button"
                                :href="href(button)"
                                target="_blank"
                                rel="noopener noreferrer"
                                dir="auto"
                                >{{ button.title }}</a
                            >
                        </article>
                    </div>

                    <div v-else-if="row.message.cards && links(row.message.cards).length" class="try-link-buttons">
                        <a
                            v-for="(button, j) in links(row.message.cards)"
                            :key="j"
                            class="try-link-button"
                            :href="href(button)"
                            target="_blank"
                            rel="noopener noreferrer"
                            dir="auto"
                            >{{ button.title }}</a
                        >
                    </div>

                    <p v-if="row.message.id === receiptId" class="try-receipt">
                        <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">
                            <circle cx="8" cy="8" r="7" fill="none" stroke="currentColor" stroke-width="1.5" />
                            <path d="M4.8 8.3 7 10.4l4.2-4.4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        Sent
                    </p>
                </template>

                <div v-if="state.typing" class="try-row in gap">
                    <div class="try-typing" role="status" aria-label="Typing">
                        <span /><span /><span />
                    </div>
                </div>

                <div v-if="quickReplies.length && canWrite" class="try-quick">
                    <button v-for="(button, i) in quickReplies" :key="i" type="button" :disabled="busy" dir="auto" @click="send(button.title, button.payload)">
                        {{ button.title }}
                    </button>
                </div>

                <p v-if="capReached" class="try-notice">You've used all {{ session?.cap }} messages for this test. Tap “Start over” to run it again.</p>
                <p v-else-if="finished" class="try-notice">This run has finished. Tap “Start over” to begin a new one.</p>
                <p v-if="error" class="try-notice" role="alert">{{ error }}</p>
            </div>

            <div class="try-composer">
                <input ref="fileInput" type="file" accept="image/*" hidden @change="sendPhoto" />
                <input ref="cameraInput" type="file" accept="image/*" capture="environment" hidden @change="sendPhoto" />

                <button type="button" class="try-icon" title="Send a photo" aria-label="Send a photo" :disabled="!canWrite || busy" @click="fileInput?.click()">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <rect x="3" y="4" width="18" height="16" rx="3" />
                        <circle cx="8.5" cy="9.5" r="1.6" />
                        <path d="m4 17 5-5 4 4 2-2 5 5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>

                <button type="button" class="try-icon" title="Take a photo" aria-label="Take a photo" :disabled="!canWrite || busy" @click="cameraInput?.click()">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M4 8h3l1.5-2h7L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z" stroke-linejoin="round" />
                        <circle cx="12" cy="13.5" r="3.5" />
                    </svg>
                </button>

                <div class="try-field">
                    <textarea
                        v-model="draft"
                        rows="1"
                        :maxlength="state.max_text"
                        :disabled="!canWrite"
                        placeholder="Message"
                        aria-label="Message"
                        enterkeyhint="send"
                        @input="grow"
                        @keydown="onComposerKey"
                    />
                </div>

                <button
                    type="button"
                    class="try-icon"
                    title="Send"
                    aria-label="Send"
                    :disabled="!canWrite || busy || draft.trim() === ''"
                    @click="send(draft.trim())"
                >
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true">
                        <path d="M3.3 11.1 20 4.2c.7-.3 1.4.4 1.1 1.1l-6.9 16.7c-.3.7-1.3.7-1.5 0l-2.2-6-6-2.2c-.8-.3-.8-1.3 0-1.6Z" />
                    </svg>
                </button>
            </div>

            <p class="try-foot">Test chat · {{ session?.used ?? 0 }}/{{ session?.cap ?? 0 }} messages · not a real customer conversation</p>
        </template>
    </div>
</template>
