import type { PlatformValue } from '@/types/crm';
import { computed } from 'vue';

export type ChatSkin = 'whatsapp' | 'suite';

export interface ChatSkinClasses {
    wallpaper: string;
    header: string;
    out: string;
    in: string;
    /** Colour for a *read* tick on an outgoing bubble. Sent/delivered/queued ticks on the
     *  suite skin use `text-primary-foreground/60` instead — computed at the call site,
     *  since that's a state variant, not a fixed skin colour. */
    tickRead: string;
    /** WhatsApp's brand green (`#00A884`) — the single source for it, so the send button
     *  and the audio player's seek accent never hardcode the hex twice. Empty for the
     *  suite skin, which uses the `primary`/`currentColor` tokens instead. */
    accent: string;
}

/** Pure mapping so components that already know their skin (passed down, or derived
 *  from their own `platform`/`skin` prop) can reuse the exact class set without calling
 *  the hook again. */
export function skinClasses(skin: ChatSkin): ChatSkinClasses {
    return skin === 'whatsapp'
        ? {
              wallpaper: 'chat-wallpaper-wa',
              header: 'bg-[var(--wa-header)]',
              out: 'bg-[var(--wa-out)] text-foreground',
              in: 'bg-[var(--wa-in)] text-foreground',
              tickRead: 'text-[var(--wa-read-tick)]',
              accent: '#00A884',
          }
        : {
              wallpaper: 'bg-card',
              header: 'bg-card',
              out: 'bg-primary text-primary-foreground',
              in: 'bg-[var(--suite-in)] text-foreground',
              // Read ticks on a blue outgoing bubble must not be `text-primary` (invisible
              // on `bg-primary`) — use the bubble's own foreground colour instead.
              tickRead: 'text-primary-foreground',
              accent: '',
          };
}

/** WhatsApp conversations get the WhatsApp-style skin; every other platform gets the
 *  Business Suite skin (spec §3.2). */
export function useChatSkin(platform: () => PlatformValue) {
    const skin = computed<ChatSkin>(() => (platform() === 'whatsapp' ? 'whatsapp' : 'suite'));
    const classes = computed(() => skinClasses(skin.value));
    return { skin, classes };
}
