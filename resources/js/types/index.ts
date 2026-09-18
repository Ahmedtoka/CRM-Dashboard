import type { BroadcastingConfig } from '@/echo';
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
    broadcasting: BroadcastingConfig | null;
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
