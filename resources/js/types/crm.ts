// Shapes of the Task 8 JSON resources and broadcast payloads consumed by the web UI.

export type PlatformValue = 'facebook' | 'instagram' | 'whatsapp' | 'tiktok';
export type AttachmentType = 'image' | 'audio' | 'video' | 'file' | 'sticker';
export type Role = 'admin' | 'supervisor' | 'moderator';
export type ConversationStatus = 'open' | 'pending' | 'resolved';
export type ConversationPriority = 'normal' | 'low' | 'spam';
export type MessageStatus = 'queued' | 'sent' | 'delivered' | 'read' | 'failed';
export type WindowMode = 'open' | 'human_agent' | 'template_only' | 'closed';
export type QuickReplyScope = 'shared' | 'personal';

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

/** Who's handling a conversation right now (spec §5.4, Task 15). */
export interface Handling {
    id: number;
    name: string;
    color: string | null;
    via: 'lock' | 'recent_reply';
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
    /** Bot handover routing (human bot flow): cleared on resolve / return to bot. Not the same as `priority`. */
    priority_level: HandoverPriority | null;
    queue: HandoverQueue | null;
    handover_category: string | null;
    /** Arabic label from HandoverSummary (the single labels source). */
    handover_category_label: string | null;
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
    handling: Handling | null;
    can: { reply: boolean; reset?: boolean };
}

/** ConversationUpdated broadcast: a subset of Conversation. */
export type ConversationPatch = Partial<Conversation> & { id: number };

export interface Attachment {
    id: number;
    type: AttachmentType;
    mime: string | null;
    size_bytes: number | null;
    original_name: string | null;
    width: number | null;
    height: number | null;
    duration_ms: number | null;
    status: 'pending' | 'stored' | 'failed';
    error: string | null;
    url: string | null;
    thumb_url: string | null;
}

/** Max attachments per message/composer tray (spec §1.5). */
export const MAX_ATTACHMENTS = 10;

/** One in-flight or completed upload shown in the composer's `AttachmentTray`. */
export interface PendingUpload {
    key: string;
    name: string;
    size: number;
    previewUrl: string | null;
    progress: number;
    attachment: Attachment | null;
    error: string | null;
}

export interface Message {
    id: number;
    conversation_id: number;
    direction: 'in' | 'out';
    sender_type: 'customer' | 'user' | 'bot' | 'system';
    user: UserRef | null;
    body: string | null;
    buttons?: { title: string; payload: string }[];
    payload?: string | null;
    attachments: Attachment[];
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
    mentions: number[];
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
    image_url?: string | null;
}

/** `OrderResource.display` — the combined Shopify + shipping status (Task 7). */
export interface OrderDisplay {
    payment: string | null;
    fulfillment: string | null;
    shipment_step: string | null;
    shipment_at: string | null;
}

export type MismatchReason = 'fulfilled_but_returned' | 'cancelled_but_in_transit' | 'delivered_but_unfulfilled' | 'cod_delivered_unpaid' | 'shopify_total_differs';

export interface Fulfillment {
    id: number;
    status: string | null;
    shipment_status: string | null;
    tracking_company: string | null;
    tracking_number: string | null;
    tracking_url: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface Refund {
    id: number;
    amount: number;
    note: string | null;
    restock: boolean;
    created_at: string | null;
}

export interface OrderTimelineEntry {
    at: string;
    source: 'shopify' | 'shipping' | 'crm';
    key: string;
    label_params: Record<string, string | number | null>;
}

export interface Order {
    id: number;
    order_number: string | null;
    status: 'submitting' | 'awaiting_payment' | 'confirmed' | 'cancelled' | 'failed';
    type: 'cod' | 'payment_link';
    source?: 'chat' | 'store' | null;
    platform: PlatformValue | null;
    conversation_id: number | null;
    customer?: { id: number; name: string | null; phone: string | null } | null;
    created_by?: UserRef | null;
    subtotal?: number;
    shipping_fee?: number;
    discount?: number;
    discount_type?: 'fixed' | 'percent' | null;
    discount_value?: number | null;
    total: number;
    currency?: string;
    financial_status?: string | null;
    fulfillment_status?: string | null;
    display?: OrderDisplay;
    mismatch?: boolean;
    mismatch_reason?: MismatchReason | null;
    invoice_url: string | null;
    shopify_order_id?: string | null;
    shopify_draft_order_id?: string | null;
    shopify_admin_url?: string | null;
    shipping_province_code?: string | null;
    shipping?: { name: string | null; phone: string | null; city: string | null; address: string | null };
    note?: string | null;
    last_error?: string | null;
    items?: OrderItem[];
    shipment: Shipment | null;
    fulfillments?: Fulfillment[];
    refunds?: Refund[];
    timeline?: OrderTimelineEntry[];
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

export interface CustomerAddress {
    id: number;
    name: string | null;
    phone: string | null;
    address1: string | null;
    address2: string | null;
    city: string | null;
    province: string | null;
    province_code: string | null;
    zip: string | null;
    is_default: boolean;
}

export type CustomerBadge = 'new' | 'repeat' | 'has_return';

export interface CustomerFlags {
    is_repeat: boolean;
    has_open_order: boolean;
    has_return: boolean;
    has_stuck_order: boolean;
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
    tags?: string[];
    orders_count: number;
    total_spent: number;
    shopify_orders_count?: number;
    shopify_total_spent?: number;
    badges?: CustomerBadge[];
    flags?: CustomerFlags;
    identities?: Identity[];
    addresses?: CustomerAddress[];
    orders?: Order[];
}

export interface Participant {
    user: UserRef | null;
    role: 'first' | 'continued' | 'follow_up';
    messages_count: number;
}

/** spec §4: a case a guided bot flow recorded (return/exchange, complaint, cancel/edit, delivery follow-up). */
export type CaseType = 'return_exchange' | 'complaint' | 'cancel_edit' | 'delivery_followup';
export type CaseStatus = 'new' | 'in_progress' | 'closed';
export type CasePriority = 'medium' | 'high';

export interface CasePhoto {
    id: number;
    url: string;
}

/** One block of the organised case summary (same as the conversation note). */
export interface CaseSummarySection {
    key: 'customer' | 'order' | 'request' | 'attachments' | 'alerts' | 'team_action';
    icon: string;
    title: string;
    lines: string[];
}

export interface SupportCase {
    id: number;
    type: CaseType;
    type_label: string;
    status: CaseStatus;
    priority: CasePriority;
    order_id: number | null;
    order_number: string | null;
    summary_header: string;
    summary_sections: CaseSummarySection[];
    data: Record<string, unknown>;
    photos: CasePhoto[];
    policy_notes: string[];
    assigned_to: { id: number; name: string } | null;
    conversation_id: number;
    customer: { id: number; name: string | null; phone: string | null } | null;
    created_at: string | null;
    closed_at: string | null;
}

export interface ConversationDetail {
    conversation: Conversation;
    messages: Message[];
    notes: Note[];
    customer: Customer | null;
    participants: Participant[];
    cases: SupportCase[];
    window: { mode: WindowMode; expires_at: string | null };
    lock: { holder: UserRef | null; until: string | null };
}

export interface QuickReplyCategory {
    id: number;
    name: string;
    sort: number;
}

export interface QuickReplyAttachmentRef {
    id: number;
    type: AttachmentType;
    original_name: string | null;
    thumb_url: string | null;
}

export interface QuickReply {
    id: number;
    shortcut: string;
    title: string;
    body: string;
    platforms: PlatformValue[];
    scope: QuickReplyScope;
    user_id: number | null;
    category: { id: number; name: string } | null;
    use_count: number;
    attachments: QuickReplyAttachmentRef[];
}

export interface RenderedQuickReply {
    body: string;
    attachments: Attachment[];
    missing: string[];
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
    compare_at_price?: number | null;
    stock: number;
    inventory_policy?: 'deny' | 'continue' | null;
    image_url: string | null;
}

export interface ShippingProvince {
    code: string;
    name: string;
}

export interface ShippingOption {
    rate_id: number | null;
    title: string;
    price: number | string;
}

export interface TemplatePayload {
    name: string;
    language: string;
    params: string[];
}

export type ConversationAction = 'resolve' | 'reopen' | 'return-to-bot' | 'reset';

export type HandoverPriority = 'low' | 'medium' | 'high';
export type HandoverQueue = 'agents' | 'senior';

export type InboxQuickFilter =
    | 'queue_all'
    | 'queue_high'
    | 'queue_senior'
    | 'waiting'
    | 'needs_human'
    | 'bot'
    | 'mine'
    | 'comment'
    | 'ad'
    | 'spam'
    | 'low_priority'
    | 'customer_new'
    | 'customer_repeat'
    | 'open_order'
    | 'has_return'
    | 'stuck_order';

export interface InboxFilters {
    platform: PlatformValue | null;
    status: ConversationStatus | null;
    filter: InboxQuickFilter | null;
    q: string | null;
    tag: number | null;
}

export interface CursorPage<T> {
    data: T[];
    meta?: { next_cursor: string | null; per_page?: number };
    links?: { next: string | null };
}

export interface ChannelAlert {
    id: number | string;
    platform: PlatformValue | 'shopify';
    name: string;
    last_error: string | null;
}

/** A persisted bell notification (spec §5.4, Dashboard Experience Task 14). */
export interface AppNotification {
    id: number;
    type: 'conversation.handover' | 'conversation.handover_urgent' | 'note.mention';
    data: Record<string, unknown>;
    read_at: string | null;
    created_at: string | null;
}
