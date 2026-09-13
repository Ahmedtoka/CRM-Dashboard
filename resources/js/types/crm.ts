// Shapes of the Task 8 JSON resources and broadcast payloads consumed by the web UI.

export type PlatformValue = 'facebook' | 'instagram' | 'whatsapp' | 'tiktok';
export type Role = 'admin' | 'supervisor' | 'moderator';
export type ConversationStatus = 'open' | 'pending' | 'resolved';
export type ConversationPriority = 'normal' | 'low' | 'spam';
export type MessageStatus = 'queued' | 'sent' | 'delivered' | 'read' | 'failed';
export type WindowMode = 'open' | 'human_agent' | 'template_only' | 'closed';

export interface PlatformOption {
    value: PlatformValue;
    label: string;
    color: string;
}

export interface UserRef {
    id: number;
    name: string;
    color?: string | null;
}

export interface Tag {
    id: number;
    name: string;
    color: string | null;
}

export interface Conversation {
    id: number;
    platform: PlatformValue;
    status: ConversationStatus;
    priority: ConversationPriority;
    handler: 'bot' | 'human';
    needs_human: boolean;
    source: 'direct' | 'comment' | 'ad' | null;
    unread_count: number;
    last_message_at: string | null;
    last_customer_message_at: string | null;
    waiting_since: string | null;
    last_message_preview: string | null;
    customer: { id: number; name: string | null; avatar_url: string | null } | null;
    locked_by: UserRef | null;
    first_responder: UserRef | null;
    tags: Tag[];
}

/** ConversationUpdated broadcast: a subset of Conversation. */
export type ConversationPatch = Partial<Conversation> & { id: number };

export interface Message {
    id: number;
    conversation_id: number;
    direction: 'in' | 'out';
    sender_type: 'customer' | 'user' | 'bot' | 'system';
    user: UserRef | null;
    body: string | null;
    attachments: unknown[];
    status: MessageStatus | null;
    error: string | null;
    is_template?: boolean;
    created_at: string | null;
    /** Client-only: optimistic bubble key until the server id arrives. */
    client_key?: string;
}

export interface Note {
    id: number;
    conversation_id: number;
    body: string;
    user: UserRef | null;
    created_at: string | null;
}

export interface ShipmentEvent {
    status: string;
    description: string | null;
    location: string | null;
    occurred_at: string | null;
}

export interface Shipment {
    id?: number;
    carrier?: string | null;
    status: string | null;
    tracking_number: string | null;
    last_event_at?: string | null;
    events?: ShipmentEvent[];
}

export interface OrderItem {
    id: number;
    variant_id: number | null;
    title: string;
    sku: string | null;
    qty: number;
    price: number;
}

export interface Order {
    id: number;
    order_number: string | null;
    status: 'awaiting_payment' | 'confirmed' | 'cancelled' | 'failed';
    type: 'cod' | 'payment_link';
    platform: PlatformValue | null;
    conversation_id: number | null;
    customer?: { id: number; name: string | null; phone: string | null } | null;
    created_by?: UserRef | null;
    subtotal?: number;
    shipping_fee?: number;
    discount?: number;
    total: number;
    currency?: string;
    invoice_url: string | null;
    items?: OrderItem[];
    shipment: Shipment | null;
    created_at: string | null;
}

export interface Identity {
    id: number;
    platform: PlatformValue;
    external_id: string;
    username: string | null;
    display_name: string | null;
    avatar_url: string | null;
}

export interface Customer {
    id: number;
    name: string | null;
    phone: string | null;
    email: string | null;
    city: string | null;
    address: string | null;
    avatar_url: string | null;
    notes: string | null;
    orders_count: number;
    total_spent: number;
    identities?: Identity[];
    orders?: Order[];
}

export interface Participant {
    user: UserRef | null;
    role: 'first' | 'continued' | 'follow_up';
    messages_count: number;
}

export interface ConversationDetail {
    conversation: Conversation;
    messages: Message[];
    notes: Note[];
    customer: Customer | null;
    participants: Participant[];
    window: { mode: WindowMode; expires_at: string | null };
    lock: { holder: UserRef | null; until: string | null };
}

export interface QuickReply {
    id: number;
    shortcut: string;
    title: string;
    body: string;
    platforms: PlatformValue[];
}

export interface City {
    id: number;
    name_ar: string;
    name_en: string | null;
    shipping_fee: number | string;
}

export interface ProductVariant {
    id: number;
    product_id: number;
    product_title: string | null;
    title: string | null;
    sku: string | null;
    price: number;
    stock: number;
    image_url: string | null;
}

export interface TemplatePayload {
    name: string;
    language: string;
    params: string[];
}

export type ConversationAction = 'resolve' | 'reopen' | 'return-to-bot';

export interface InboxFilters {
    platform: PlatformValue | null;
    status: ConversationStatus | null;
    filter: 'waiting' | 'needs_human' | 'bot' | 'mine' | 'comment' | 'ad' | 'spam' | 'low_priority' | null;
    q: string | null;
}

export interface CursorPage<T> {
    data: T[];
    meta?: { next_cursor: string | null; per_page?: number };
    links?: { next: string | null };
}

export interface ChannelAlert {
    id: number;
    platform: PlatformValue;
    name: string;
    last_error: string | null;
}
