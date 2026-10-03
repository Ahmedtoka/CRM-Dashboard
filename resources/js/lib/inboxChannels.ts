import type { User } from '@/types';
import type Echo from 'laravel-echo';

type InboxUser = Pick<User, 'role' | 'platforms'>;
type Handler = (payload: never) => void;

/**
 * The private channels a user's inbox listeners belong on. Supervisors and admins get the
 * all-platform `inbox` channel; a moderator only gets `inbox.platform.<p>` for each platform she
 * is allowed on, so the server never sends her another platform's message bodies (the `inbox`
 * channel itself is refused for her).
 */
export function inboxChannels(user: InboxUser): string[] {
    if (user.role === 'admin' || user.role === 'supervisor') return ['inbox'];

    return [...new Set(user.platforms ?? [])].map((p) => `inbox.platform.${p}`);
}

/**
 * Binds `handlers` (event name -> handler) on every inbox channel of the user and returns a function
 * that detaches exactly those handlers. Channels are shared by several listeners (the inbox list,
 * notifications), so this never calls `leave()`: that would unbind everyone else's listeners too.
 */
export function listenInbox(echo: Echo<'reverb'> | null, user: InboxUser, handlers: Record<string, Handler>): () => void {
    if (!echo) return () => undefined;

    const channels = inboxChannels(user).map((name) => {
        const channel = echo.private(name);
        Object.entries(handlers).forEach(([event, handler]) => channel.listen(event, handler));

        return channel;
    });

    return () =>
        channels.forEach((channel) => Object.entries(handlers).forEach(([event, handler]) => channel.stopListening(event, handler)));
}
