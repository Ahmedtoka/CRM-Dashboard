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
}

export interface SharedData {
    [key: string]: unknown;
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    locale: 'ar' | 'en';
    platforms: PlatformOption[];
    channelAlerts: ChannelAlert[];
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
    created_at?: string;
    updated_at?: string;
}

export type BreadcrumbItemType = BreadcrumbItem;
