import { useApi } from '@/composables/useApi';
import { useEcho } from '@/composables/useEcho';
import { useI18n } from '@/composables/useI18n';
import type { NotificationPreferences, SharedData } from '@/types';
import type { AppNotification, Message, PlatformValue } from '@/types/crm';
import { router, usePage } from '@inertiajs/vue3';
import { effectScope, ref, watch, type EffectScope } from 'vue';

/** Maps a notification/broadcast `type` to its i18n leaf under `notifications.types.*`. */
export const TYPE_KEY = { 'conversation.handover': 'handover', 'conversation.handover_urgent': 'handover_urgent', 'note.mention': 'mention' } as const;

/** Desktop notification bodies never carry more than this many characters of a message (privacy ruling). */
const PREVIEW_MAX = 80;

const DEFAULT_PREFS: NotificationPreferences = { sound: true, desktop_notifications: false, notify_scope: 'all_visible', sound_volume: 0.6 };

const items = ref<AppNotification[]>([]);
const unreadNotifications = ref(0);
const unreadConversations = ref(0);
const prefs = ref<NotificationPreferences>({ ...DEFAULT_PREFS });
const permission = ref<NotificationPermission | 'unsupported'>(typeof Notification === 'undefined' ? 'unsupported' : Notification.permission);
/**
 * The user id the live session was started for (final fix wave I2). `start()` is
 * keyed on it: a different (or no) authenticated user tears the old session down
 * — channels, effect scope, items, counts, prefs — before anything new starts.
 */
let startedForUserId: number | null = null;
/** Undoes every Echo subscription made by the current session (see `start()`). */
let teardownListeners: Array<() => void> = [];
/** Bumped on every reset so a response still in flight for a previous session is discarded. */
let generation = 0;
let lastSound = 0;
let refreshTimer: number | undefined;
let refreshMaxTimer: number | undefined;
const audio = typeof Audio !== 'undefined' ? new Audio('/sounds/notify.wav') : null;

/**
 * Everything `start()` sets up that must outlive a single component (the poll
 * fallback and the tab-title watcher) runs inside this module-level, detached
 * scope instead of whichever component happens to call `start()` first.
 * `AppLayout` is not a persistent Inertia layout in this app — every page
 * wraps its own `<AppLayout>` — so a component-scoped effect would be torn
 * down (and never re-created, `start()` being idempotent) on the very next
 * page visit.
 */
let sessionScope: EffectScope | null = null;

/** Read by `app.ts`'s Inertia `title` callback — kept outside the composable so it works before any component mounts it. */
export const unreadConversationsCount = () => unreadConversations.value;

function preview(text: string): string {
    return text.length > PREVIEW_MAX ? `${text.slice(0, PREVIEW_MAX)}…` : text;
}

/** Stops the current session and clears every piece of per-user state it held. */
function resetSession(): void {
    generation++;
    teardownListeners.forEach((undo) => {
        try {
            undo();
        } catch {
            // A socket already gone can't be unsubscribed from — nothing left to undo.
        }
    });
    teardownListeners = [];
    sessionScope?.stop();
    sessionScope = null;
    window.clearTimeout(refreshTimer);
    window.clearTimeout(refreshMaxTimer);
    refreshTimer = undefined;
    refreshMaxTimer = undefined;
    items.value = [];
    unreadNotifications.value = 0;
    unreadConversations.value = 0;
    prefs.value = { ...DEFAULT_PREFS };
    lastSound = 0;
    startedForUserId = null;
    // The title watcher was stopped with the scope above, so drop the badge by hand.
    document.title = document.title.replace(/^\(\d+\)\s/, '');
}

type InboundMessage = Message & {
    conversation?: {
        platform: string;
        customer_name: string | null;
        last_responder_id: number | null;
        locked_by_id: number | null;
        priority: string | null;
    } | null;
};

export function useNotifications() {
    const api = useApi();
    const page = usePage<SharedData>();
    const { t } = useI18n();
    // useEcho() reads usePage() and registers scope-disposal cleanup, so it must run
    // during component setup — never lazily inside start().
    const { echo, poll } = useEcho();

    async function refresh(): Promise<void> {
        const gen = generation;
        const { data } = await api.get<{ data: AppNotification[]; unread_notifications: number; unread_conversations: number }>('/notifications', { silent: true });
        if (gen !== generation) return; // answered for a session that has since been reset
        items.value = data.data;
        unreadNotifications.value = data.unread_notifications;
        unreadConversations.value = data.unread_conversations;
    }

    function runRefresh(): void {
        window.clearTimeout(refreshTimer);
        window.clearTimeout(refreshMaxTimer);
        refreshTimer = undefined;
        refreshMaxTimer = undefined;
        void refresh().catch(() => undefined);
    }

    // Debounced 2s after the last event, but never starved past 10s under
    // steady traffic (each new message would otherwise keep resetting the
    // 2s timer forever without the max-wait floor).
    const scheduleRefresh = () => {
        window.clearTimeout(refreshTimer);
        refreshTimer = window.setTimeout(runRefresh, 2000);
        if (!refreshMaxTimer) {
            refreshMaxTimer = window.setTimeout(runRefresh, 10000);
        }
    };

    function play(): void {
        const now = Date.now();
        if (!prefs.value.sound || !audio || now - lastSound < 3000) return;
        lastSound = now;
        audio.volume = prefs.value.sound_volume;
        void audio.play().catch(() => undefined);
    }

    function desktop(title: string, body: string, conversationId: number | null): void {
        if (!prefs.value.desktop_notifications || permission.value !== 'granted' || !document.hidden) return;
        try {
            const n = new Notification(title, { body, icon: '/favicon.ico', tag: conversationId ? `c-${conversationId}` : undefined });
            n.onclick = () => {
                window.focus();
                if (conversationId) router.visit(`/inbox?c=${conversationId}`);
                n.close();
            };
        } catch {
            // Some browsers/webviews (e.g. Android Chrome in certain contexts) throw
            // "Illegal constructor" for `new Notification()` despite reporting
            // permission as granted — degrade silently, there's nothing else to do.
        }
    }

    /** Whether the user can see this platform's conversations at all (supervisor+ see every platform). */
    function canSeePlatform(platform: string | undefined): boolean {
        if (!platform) return true;
        const role = page.props.auth.user.role;
        if (role === 'admin' || role === 'supervisor') return true;

        return (page.props.auth.user.platforms ?? []).includes(platform as PlatformValue);
    }

    function onInbound(message: InboundMessage): void {
        if (message.direction !== 'in') return;
        scheduleRefresh();
        const meId = page.props.auth.user.id;
        const c = message.conversation;
        if (!canSeePlatform(c?.platform)) return;
        if (c?.priority === 'spam' || c?.priority === 'low') return;
        if (prefs.value.notify_scope === 'mine_and_handover' && c?.last_responder_id !== meId && c?.locked_by_id !== meId) return;
        const type = message.attachments?.[0]?.type;
        play();
        desktop(
            `${c?.customer_name ?? t('notifications.customer')} · ${page.props.platforms.find((p) => p.value === c?.platform)?.label ?? ''}`,
            preview(message.body || (type ? t(`media.preview_${type}`) : '')),
            message.conversation_id,
        );
    }

    function onUserNotified(e: { type: AppNotification['type']; data: Record<string, unknown> }): void {
        items.value = [{ id: Number(e.data.id), type: e.type, data: e.data, read_at: null, created_at: new Date().toISOString() }, ...items.value].slice(0, 30);
        unreadNotifications.value += 1;
        play(); // handover and mentions always notify (subject to the sound/desktop preferences)
        desktop(t(`notifications.types.${TYPE_KEY[e.type]}`), preview(String(e.data.customer_name ?? e.data.excerpt ?? '')), Number(e.data.conversation_id) || null);
    }

    /**
     * Idempotent per user: every page's AppLayout calls this, so the same user is a
     * no-op. A different user id than the session was started for (or no user at
     * all) resets the previous session first — leaving its `user.{id}` channel,
     * detaching its `inbox` listeners, stopping its scope and clearing its state.
     */
    function start(): void {
        const userId = page.props.auth.user?.id ?? null;

        if (userId === startedForUserId) return;
        if (startedForUserId !== null) resetSession();
        if (userId === null) return;

        startedForUserId = userId;
        prefs.value = { ...DEFAULT_PREFS, ...(page.props.auth.user.preferences ?? {}) };
        void refresh().catch(() => undefined);

        if (echo) {
            const inbox = echo.private('inbox').listen('MessageCreated', onInbound).listen('ConversationUpdated', scheduleRefresh);
            // `inbox` is shared with the inbox page's own listeners: detach only ours.
            teardownListeners.push(() => inbox.stopListening('MessageCreated', onInbound).stopListening('ConversationUpdated', scheduleRefresh));

            const userChannel = `user.${userId}`;
            echo.private(userChannel).listen('UserNotified', onUserNotified);
            teardownListeners.push(() => echo.leave(userChannel));
        }

        // The poll fallback and the tab-title watcher must survive past whichever
        // component's setup happens to call start() first — run them in a
        // detached scope created once for the whole app session (see sessionScope).
        sessionScope = effectScope(true);
        sessionScope.run(() => {
            poll(refresh);

            // Keeps the tab-title `(n) …` badge in sync between full page visits (app.ts
            // reads unreadConversationsCount() once per title change, which isn't reactive on its own).
            watch(unreadConversations, (n) => {
                document.title = document.title.replace(/^\(\d+\)\s/, '');
                if (n > 0) document.title = `(${n}) ${document.title}`;
            });
        });
    }

    async function markRead(ids?: number[]): Promise<void> {
        const { data } = await api.post<{ unread_notifications: number }>('/notifications/read', ids ? { ids } : {});
        unreadNotifications.value = data.unread_notifications;
        items.value = items.value.map((n) => (!ids || ids.includes(n.id) ? { ...n, read_at: n.read_at ?? new Date().toISOString() } : n));
    }

    async function savePrefs(patch: Partial<NotificationPreferences>): Promise<void> {
        const { data } = await api.patch<{ data: NotificationPreferences }>('/settings/notifications', patch);
        prefs.value = data.data;
    }

    async function requestDesktopPermission(): Promise<void> {
        if (permission.value === 'unsupported') return;
        permission.value = await Notification.requestPermission(); // only ever called from a click handler
        if (permission.value === 'granted') await savePrefs({ desktop_notifications: true });
    }

    return { items, unreadNotifications, unreadConversations, prefs, permission, refresh, markRead, savePrefs, requestDesktopPermission, start };
}
