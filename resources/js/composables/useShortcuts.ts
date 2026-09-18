import { computed, onBeforeUnmount, onMounted, shallowRef } from 'vue';

export type ShortcutGroup = 'global' | 'inbox' | 'composer';

export interface ShortcutDef {
    id: string;
    /** Combos this shortcut fires on, e.g. `['j', 'arrowdown']`. */
    keys: string[];
    labelKey: string;
    group: ShortcutGroup;
    /** Fires even while focus is in an input/textarea/select/contenteditable. */
    allowInInput?: boolean;
    /**
     * Extra guard evaluated once a combo has matched, before `preventDefault()`.
     * Returning false lets the keydown proceed exactly as if this shortcut
     * weren't registered at all (no preventDefault, no handler call) — used
     * e.g. so `arrowdown`/`arrowup` only steal the event inside the
     * conversation list, never inside the thread.
     */
    when?: (event: KeyboardEvent) => boolean;
    handler?: (event: KeyboardEvent) => void;
}

const registry = shallowRef<ShortcutDef[]>([]);
const isMac = typeof navigator !== 'undefined' && /mac|iphone|ipad/i.test(navigator.platform);
let listening = false;

function isTyping(target: EventTarget | null): boolean {
    const el = target as HTMLElement | null;
    return !!el && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName));
}

/**
 * A Radix dialog/sheet/dropdown/select content element renders `role="dialog"`
 * (Dialog, Sheet), `role="menu"` (DropdownMenu) or `role="listbox"` (a native
 * or Radix listbox) with `data-state="open"` while visible; the composer's
 * hand-rolled locked-conversation confirm banner renders `role="alertdialog"`
 * (no `data-state`, it's only ever in the DOM while showing). Used to suspend
 * inbox shortcuts so they never fight an open overlay's own key handling
 * (controller ruling: inbox shortcuts are suspended except Escape while a
 * dialog, sheet, menu or the confirm banner is open).
 */
function isOverlayOpen(): boolean {
    if (typeof document === 'undefined') return false;
    return !!document.querySelector(
        '[role="dialog"][data-state="open"], [role="menu"][data-state="open"], [role="listbox"][data-state="open"], [role="alertdialog"]',
    );
}

export function matchesKeys(event: KeyboardEvent, combo: string): boolean {
    const parts = combo.toLowerCase().split('+');
    const key = parts.pop() as string;
    const wantMod = parts.includes('mod');
    const wantShift = parts.includes('shift');
    const modPressed = isMac ? event.metaKey : event.ctrlKey;
    if (wantMod !== modPressed) return false;
    // A shortcut that asks for no modifier must not fire with a stray Ctrl,
    // Meta or Alt held — including the platform's *other* mod key (e.g. Ctrl
    // held on Mac, where `mod` means Cmd, must not trigger a plain letter).
    if (event.altKey || (!wantMod && (event.ctrlKey || event.metaKey))) return false;
    if (key !== '?' && wantShift !== event.shiftKey) return false;

    // Named keys (Escape, Enter, arrows, ...) are layout-independent — `event.key`
    // reports the same string regardless of keyboard layout, so it's used as-is.
    if (event.key.toLowerCase() === key) return true;
    // Single letters and punctuation are matched by `event.code` (the physical
    // key position) too, so shortcuts keep working when a non-Latin layout
    // (e.g. Arabic) is active and `event.key` holds a different character.
    if (key === '?') return event.key === '?' || event.code === 'Slash';
    if (key === '/') return event.code === 'Slash';
    if (/^[a-z]$/.test(key)) return event.code === `Key${key.toUpperCase()}`;
    return false;
}

export function formatKeys(combo: string): string {
    return combo
        .split('+')
        .map((p) => ({ mod: isMac ? '⌘' : 'Ctrl', shift: 'Shift', escape: 'Esc', enter: 'Enter', arrowdown: '↓', arrowup: '↑' })[p] ?? p.toUpperCase())
        .join(isMac ? '' : '+');
}

function onKeydown(event: KeyboardEvent): void {
    // 229 is the historical IME-composition keyCode some browsers still report
    // for the commit keystroke even when `isComposing` has already flipped back.
    if (event.isComposing || event.keyCode === 229 || event.defaultPrevented) return;
    const typing = isTyping(event.target);
    const overlay = isOverlayOpen();

    for (const def of [...registry.value].reverse()) {
        if (!def.handler || (typing && !def.allowInInput)) continue;
        // Inbox shortcuts never fire over an open dialog/sheet/menu/confirm
        // banner — only Escape (and anything else explicitly listing it) gets through.
        if (overlay && def.group === 'inbox' && !def.keys.includes('escape')) continue;
        if (def.keys.some((combo) => matchesKeys(event, combo)) && (!def.when || def.when(event))) {
            event.preventDefault();
            def.handler(event);
            return;
        }
    }
}

/** Registers `defs` for the calling component's lifetime (mounted → unmounted). */
export function useShortcuts(defs: ShortcutDef[]): void {
    onMounted(() => {
        registry.value = [...registry.value.filter((d) => !defs.some((n) => n.id === d.id)), ...defs];
        if (!listening) {
            window.addEventListener('keydown', onKeydown);
            listening = true;
        }
    });
    onBeforeUnmount(() => (registry.value = registry.value.filter((d) => !defs.includes(d))));
}

/** Composer shortcuts are handled inside Composer.vue's own keydown handler, not the registry — these entries exist so the cheat-sheet can still list them. */
export const DOC_ONLY: ShortcutDef[] = [
    { id: 'composer.send', keys: ['enter'], labelKey: 'shortcuts.send', group: 'composer' },
    { id: 'composer.newline', keys: ['shift+enter'], labelKey: 'shortcuts.newline', group: 'composer' },
    { id: 'composer.replies', keys: ['/'], labelKey: 'shortcuts.saved_replies', group: 'composer' },
    { id: 'composer.send_resolve', keys: ['mod+enter'], labelKey: 'shortcuts.send_resolve', group: 'composer' },
];

export function useShortcutRegistry() {
    return { list: computed(() => [...registry.value, ...DOC_ONLY]) };
}

/** '' when `id` isn't registered, else its first combo formatted for the platform. */
export function shortcutHint(id: string): string {
    const def = [...registry.value, ...DOC_ONLY].find((d) => d.id === id);
    return def ? formatKeys(def.keys[0]) : '';
}
