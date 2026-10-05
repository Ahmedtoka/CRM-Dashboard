// Shapes of the Task 8 JSON resources and broadcast payloads consumed by the web UI.

export type PlatformValue = 'facebook' | 'instagram' | 'whatsapp' | 'tiktok';
export type AttachmentType = 'image' | 'audio' | 'video' | 'file' | 'sticker';
export type Role = 'admin' | 'supervisor' | 'moderator' | 'media_buyer' | 'content';
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
    /** what she said she needs when she asked for a person («موضوع التحويل», flow 7) */
    handover_topic?: string | null;
    handler: 'bot' | 'human';
    needs_human: boolean;
    source: 'direct' | 'comment' | 'ad' | null;
    /** Which ad (or m.me link) the conversation came through; null when none. */
    ad?: { id: string | null; title: string | null; name: string | null; adset: string | null; campaign: string | null; post_id: string | null; photo_url: string | null; ref: string | null } | null;
    /** A run of a public team test link (design 2026-09-21): shown with a «تجربة» badge. */
    is_test: boolean;
    unread_count: number;
    last_message_at: string | null;
    last_customer_message_at: string | null;
    waiting_since: string | null;
    last_message_preview: string | null;
    /** Who wrote the preview (list rows only): a moderator's own reply gets «إنتي: ». */
    last_message_sender?: 'customer' | 'user' | 'bot' | 'system' | null;
    customer: { id: number; name: string | null; avatar_url: string | null } | null;
    locked_by: UserRef | null;
    first_responder: UserRef | null;
    tags: Tag[];
    handling: Handling | null;
    /** The moderator the handover queue gave this conversation to. */
    assignee: UserRef | null;
    /** Her open handover-queue ticket (called / active window); null otherwise. Not the `queue` agents/senior badge. */
    queue_entry: ConversationQueueEntry | null;
    /** Any non-terminal ticket (waiting included): the list row's state badge reads it. Null with no ticket. */
    queue_state?: ConversationQueueState | null;
    /** The last moderator who replied: «مع [اسم]» when nobody is assigned (R3). */
    last_responder_id?: number | null;
    /** Her open support case (even before a ticket is called): the thread shows «عندها كيس مفتوح #N». */
    open_case_id: number | null;
    can: { reply: boolean; reset?: boolean };
}

export interface ConversationQueueState {
    status: 'waiting' | 'called' | 'active';
    ticket: number;
    priority: string;
    /** QueueEntryResource::reply_overdue: an open window whose customer already got the apology. */
    overdue: boolean;
    assigned_user_id: number | null;
}

export interface ConversationQueueEntry {
    id: number;
    assigned_user_id: number | null;
    priority: QueuePriority;
    ticket: number;
    window_no: number | null;
    status: string;
    kind: string;
    delivered_at: string | null;
    bot_summary: Record<string, unknown> | null;
    open_case_id: number | null;
}

export type QueuePriority = 'returning' | 'escalation' | 'live' | 'overnight' | 'manual';
export type QueueEntryStatus = 'waiting' | 'called' | 'active' | 'closed' | 'abandoned' | 'cancelled';
/** The reasons a person closes a window with; every other close reason is the system's. */
export type QueueCloseReason = 'inquiry' | 'problem' | 'case';
export type SupportCaseType = 'return_exchange' | 'return' | 'exchange' | 'complaint' | 'cancel_edit' | 'delivery_followup';
/** `checking_out`: she pressed «خروج» with windows open (no new chats until they close). */
export type ShiftMemberStatus = 'available' | 'busy' | 'pending_break' | 'break' | 'offline' | 'checking_out' | 'left';

/** QueueEntryResource: one ticket (GET /queue/me, the `QueueEntryUpdated` broadcast). */
export interface QueueEntry {
    id: number;
    /** Show `ticket % 100000`: a transferred ticket is parked at +100000 so the follow-up keeps the number. */
    ticket: number;
    status: QueueEntryStatus;
    priority: QueuePriority;
    kind: string;
    platform: PlatformValue | null;
    customer: { id: number; name: string | null; avatar_url: string | null } | null;
    conversation_id: number;
    assigned_user_id: number | null;
    reserved_user_id: number | null;
    window_no: number | null;
    enqueued_at: string | null;
    delivered_at: string | null;
    first_reply_at: string | null;
    eta_seconds: number | null;
    wait_seconds: number | null;
    bot_summary: Record<string, unknown> | null;
    /** The first line of what she asked for, from the bot's summary. */
    request_line: string | null;
    rule: string | null;
    close_reason: string | null;
    /** Seconds to the auto-close, as of the moment the server answered; null while the clock is not running. */
    silence_left_seconds: number | null;
    silence_warned: boolean;
    /** The moderator this customer was taken from for not replying: never given back to her. */
    excluded_user_id: number | null;
    /** She has been waiting for the assignee's reply since then; null when she is not (open windows only). */
    awaiting_reply_since: string | null;
    /** She waited past the apology: the window shows orange. */
    reply_overdue: boolean;
    /** Seconds to the hand-off to a colleague, as of the server's answer; null without that clock and for an escalation. */
    handoff_left_seconds: number | null;
    return_priority_until: string | null;
    reopened_from_entry_id: number | null;
    /** Her open support case when she took the ticket: «عندها كيس مفتوح #N». */
    open_case_id: number | null;
}

/** ShiftMemberResource: one moderator's desk. */
export interface ShiftMember {
    id: number;
    shift_id: number;
    user: UserRef | null;
    status: ShiftMemberStatus;
    /** Her moderator is logged in right now (heartbeat in the last 2 minutes). */
    online: boolean;
    cap: number;
    open_count: number;
    break_at: string | null;
    /** When her break started; the strip and the board count the time since (no automatic return). */
    break_started_at: string | null;
    break_ends_at: string | null;
    joined_at: string | null;
    today: Record<string, number>;
}

/** GET /queue/me `attendance` (attendance design §3): «بدأت شغل» and when it can be pressed. */
export interface MyAttendance {
    /** An active account with at least one platform: she may check in. */
    eligible: boolean;
    /** A shift runs now (or its time came): «بدأت شغل» is enabled. */
    shift_open: boolean;
    shift_name: string | null;
    /** When the next shift starts, while none runs; null otherwise or without templates. */
    next_starts_at: string | null;
}

/** GET /queue/me. With the queue off everything but `enabled` is empty. */
export interface MyQueuePayload {
    enabled: boolean;
    member: ShiftMember | null;
    entries: QueueEntry[];
    settings: { silence_warn_seconds: number; silence_close_seconds: number; windows_per_moderator: number; break_minutes: number } | null;
    /** The leader of the open shift (nobody above her to escalate to); null with no shift or no leader. */
    leader_user_id: number | null;
    /** Null with the queue off. */
    attendance: MyAttendance | null;
    server_time: string;
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

/** A card button: a link, or a call (Messenger only). Mirrors app/Channels/Cards/OutboundCards.php. */
export interface OutboundCardButton {
    type: 'web_url' | 'phone';
    title: string;
    url?: string;
    phone?: string;
}

export interface OutboundCard {
    title: string;
    subtitle?: string | null;
    text?: string | null;
    buttons: OutboundCardButton[];
}

export type OutboundCards = { type: 'generic'; cards: OutboundCard[] } | { type: 'button'; buttons: OutboundCardButton[] };

export interface Message {
    id: number;
    conversation_id: number;
    direction: 'in' | 'out';
    sender_type: 'customer' | 'user' | 'bot' | 'system';
    user: UserRef | null;
    body: string | null;
    buttons?: { title: string; payload: string }[];
    /** rich cards the bot sent with this message (the branch cards, the store-link button) */
    cards?: OutboundCards | null;
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
    /** Shopify's own name for the order ("#1381"); null until it is on Shopify. */
    shopify_order_name?: string | null;
    /** When the order was placed in the store. */
    placed_at?: string | null;
    /** Shopify's own updated_at: the last change made in Shopify. */
    shopify_updated_at?: string | null;
    /** The last time the CRM read the order from Shopify (any read, even one that changed nothing). */
    last_synced_at?: string | null;
    /** The last change to the CRM row. */
    updated_at?: string | null;
    on_shopify?: boolean;
    /** Cancelled, refunded/voided or delivered: never refreshed in the background (Order::isFinalForSync). */
    is_final?: boolean;
    paid_at?: string | null;
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
export type CaseType = 'return' | 'exchange' | 'return_exchange' | 'complaint' | 'cancel_edit' | 'delivery_followup';
export type CaseStatus = 'new' | 'in_progress' | 'closed';
export type CasePriority = 'low' | 'normal' | 'medium' | 'high';

export interface CasePhoto {
    id: number;
    url: string;
}

/** One block of the organised case summary (same as the conversation note). */
export interface CaseSummarySection {
    key: 'customer' | 'order' | 'items' | 'request' | 'attachments' | 'alerts' | 'team_action';
    title: string;
    lines: string[];
    /** Set on the alerts section when there is nothing to warn about (its one line is the "none" placeholder). */
    empty?: boolean;
}

export interface CaseItem {
    line_item_id: number | null;
    title: string;
    variant: string | null;
    qty: number;
    price: number | null;
    exchange_only: boolean;
}

export interface CaseExchangeProduct {
    title: string;
    handle: string | null;
    url: string | null;
    price: number | null;
    image: string | null;
    variant_title: string | null;
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
    /** items picked in the return flow (spec 2026-09-19 §2) */
    items: CaseItem[];
    /** the owner's return flow (2026-09-19): `return` or `exchange`, null for other cases */
    request_kind?: 'return' | 'exchange' | null;
    reason?: string | null;
    /** the product she linked for an exchange */
    exchange_product?: CaseExchangeProduct | null;
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
    | 'test'
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

export type InboxStatusFilter = 'open' | 'pending' | 'resolved' | 'waiting' | 'with_moderator' | 'bot' | 'closed';
export type InboxQueueFilter = 'waiting' | 'window' | 'overdue' | 'returning';

/** The inbox list filters, all kept in the URL (spec §1.2). */
export interface InboxFilters {
    status: InboxStatusFilter | null;
    queue: InboxQueueFilter | null;
    /** 'me' | 'none' | '<user id>' */
    assignee: string | null;
    /** Several at once, joined with AND (R4). */
    flags: InboxQuickFilter[];
    platform: PlatformValue | null;
    tag: number | null;
    q: string | null;
}

/** GET /inbox/conversations/counts: each value over a capped sub-select (> capped_at shows "999+"). */
export interface InboxCounts {
    status: Record<'open' | 'waiting' | 'with_moderator' | 'bot' | 'closed', number>;
    /** Null while the handover queue is off. */
    queue: Record<InboxQueueFilter, number> | null;
    capped_at: number;
}

/** An active moderator or supervisor, for the moderator filter and the row's «مع [اسم]». */
export interface InboxModerator {
    id: number;
    name: string;
    color: string | null;
}

export interface CursorPage<T> {
    data: T[];
    /** `search_truncated`: the substring search matched more than 500 customers (only the most recent were searched). */
    meta?: { next_cursor: string | null; per_page?: number; search_truncated?: boolean };
    links?: { next: string | null };
    /** `like` when the list search fell back to a substring match: send `qmode=like` with every later page. */
    search_mode?: 'like' | null;
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
    type:
        | 'conversation.handover'
        | 'conversation.handover_urgent'
        | 'note.mention'
        | 'channel.problem'
        | 'queue.assigned'
        | 'queue.escalation_waiting'
        | 'queue.mass_offline'
        | 'queue.reply_overdue'
        | 'queue.reply_overdue_leader'
        | 'queue.member_not_arrived'
        | 'queue.break_overrun'
        | 'ads.need_stop'
        | 'ads.token_invalid';
    data: Record<string, unknown>;
    read_at: string | null;
    created_at: string | null;
}
