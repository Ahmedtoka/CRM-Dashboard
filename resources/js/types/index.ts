import type { BroadcastingConfig } from '@/echo';
import type { AdsAccess } from '@/types/ads';
import type { ChannelAlert, PlatformOption, PlatformValue, Role } from '@/types/crm';
import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
    children?: NavItem[];
    /** Sub-section label: consecutive children sharing one render under a small heading. */
    section?: string;
    /** Active only on this exact path (a parent-path child such as /ads would light up on every sub-page). */
    exact?: boolean;
    /** Count shown at the end of the item (open decisions, drafts to review). Hidden when 0 or missing. */
    badge?: number | null;
    /** Query string appended to the link only (carried filters); `isActive` still compares the path. */
    query?: string;
    /** Other path prefixes that light this item up (setup tabs live under /ads/accounts and /ads/sync). */
    match?: string[];
}

export interface SharedData {
    [key: string]: unknown;
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    locale: 'ar' | 'en';
    platforms: PlatformOption[];
    channelAlerts: ChannelAlert[];
    devTools?: boolean;
    /** The live board is in the menu: supervisors, admins and the leader of the open shift. */
    canSeeBoard?: boolean;
    broadcasting: BroadcastingConfig | null;
    /** Ads Hub access, shared only on ads.* routes (null elsewhere). */
    ads?: AdsAccess | null;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    role?: Role | null;
    color?: string | null;
    locale?: 'ar' | 'en' | null;
    platforms?: PlatformValue[];
    preferences?: NotificationPreferences;
    created_at?: string;
    updated_at?: string;
}

export interface NotificationPreferences {
    sound: boolean;
    desktop_notifications: boolean;
    notify_scope: 'all_visible' | 'mine_and_handover';
    sound_volume: number;
}

export type BreadcrumbItemType = BreadcrumbItem;
