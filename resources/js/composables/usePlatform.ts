import type { SharedData } from '@/types';
import type { PlatformValue } from '@/types/crm';
import { usePage } from '@inertiajs/vue3';
import { Facebook, Instagram, MessageCircle, Music2, type LucideIcon } from 'lucide-vue-next';
import { computed, toValue, type ComputedRef, type MaybeRefOrGetter } from 'vue';

const icons: Record<PlatformValue, LucideIcon> = {
    facebook: Facebook,
    instagram: Instagram,
    whatsapp: MessageCircle,
    tiktok: Music2,
};

const fallback: Record<PlatformValue, { label: string; color: string }> = {
    facebook: { label: 'Messenger', color: '#0866FF' },
    instagram: { label: 'Instagram', color: '#E1306C' },
    whatsapp: { label: 'WhatsApp', color: '#25D366' },
    tiktok: { label: 'TikTok', color: '#000000' },
};

/** A near-black brand colour (TikTok) vanishes on a dark background: follow the theme's foreground instead. */
function readable(hex: string): string {
    const m = /^#?([0-9a-f]{6})$/i.exec(hex);
    if (!m) return hex;
    const n = parseInt(m[1], 16);
    const lum = (0.2126 * ((n >> 16) & 255) + 0.7152 * ((n >> 8) & 255) + 0.0722 * (n & 255)) / 255;

    return lum < 0.05 ? 'hsl(var(--foreground))' : hex;
}

export interface PlatformInfo {
    label: string;
    color: string;
    icon: LucideIcon;
}

/** Label / brand color (from the shared `platforms` prop) and icon for a platform value. */
export function usePlatform(platform: MaybeRefOrGetter<PlatformValue | null | undefined>): ComputedRef<PlatformInfo> {
    const page = usePage<SharedData>();

    return computed(() => {
        const value = toValue(platform) ?? 'facebook';
        const shared = page.props.platforms?.find((p) => p.value === value);
        const base = fallback[value] ?? fallback.facebook;

        return {
            label: shared?.label ?? base.label,
            color: readable(shared?.color ?? base.color),
            icon: icons[value] ?? MessageCircle,
        };
    });
}
