// WhatsApp order confirmation (spec 2026-10-16, W4): the shapes App\Confirmations\Ui\ConfirmationBoard sends.

export const CONFIRMATION_STATUSES = [
    'scheduled',
    'sent',
    'confirmed',
    'edit_requested',
    'cancel_requested',
    'no_response',
    'skipped',
    'failed',
    'called_confirmed',
    'called_cancelled',
    'unreachable',
] as const;
export type ConfirmationStatus = (typeof CONFIRMATION_STATUSES)[number];

/** App\Confirmations\SkipReason. */
export const SKIP_REASONS = [
    'disabled',
    'no_phone',
    'too_old',
    'before_floor',
    'test_order',
    'cancelled',
    'chat_order',
    'no_whatsapp_channel',
    'duplicate',
    'pos_order',
    'too_late',
] as const;
export type SkipReason = (typeof SKIP_REASONS)[number];

export const CONFIRMATION_TABS = ['all', 'needs_call', 'requests', 'confirmed', 'skipped'] as const;
export type ConfirmationTab = (typeof CONFIRMATION_TABS)[number];

/** OrderConfirmation::CALL_OUTCOMES. */
export const CALL_OUTCOMES = ['confirmed', 'cancelled', 'unreachable'] as const;
export type CallOutcome = (typeof CALL_OUTCOMES)[number];

export interface ConfirmationRow {
    id: number;
    status: ConfirmationStatus;
    skip_reason: string | null;
    last_error: string | null;
    phone: string | null;
    answer: 'confirm' | 'edit' | 'cancel' | null;
    order: { id: number; name: string; total: number; currency: string | null; customer_name: string | null } | null;
    send_at: string | null;
    sent_at: string | null;
    reminded_at: string | null;
    answered_at: string | null;
    called_at: string | null;
    call_outcome: CallOutcome | null;
    caller: string | null;
    edit_note: string | null;
    case_id: number | null;
    conversation_id: number | null;
    needs_call: boolean;
    /** Staff may record her answer (ConfirmationBoard::RECORDABLE). */
    can_record: boolean;
    created_at: string | null;
}

export type TimelineKey = 'scheduled' | 'sent' | 'reminded' | 'answered' | 'no_response' | 'called' | 'skipped' | 'failed';

export interface ConfirmationTimelineStep {
    key: TimelineKey;
    at: string | null;
    /** answered: the answer; called: the outcome; skipped: the reason; failed: the error. */
    detail: string | null;
}

export interface OrderConfirmationBlock extends ConfirmationRow {
    timeline: ConfirmationTimelineStep[];
}

export interface ConfirmationCounts {
    total: number;
    sent: number;
    confirmed: number;
    answered: number;
    confirmed_share: number | null;
    cancel: number;
    edit: number;
    no_response: number;
    unreachable: number;
    waiting: number;
    skipped: number;
    tabs: Record<ConfirmationTab, number>;
}

/** The «النهارده» card (TodayCards::confirmations). */
export interface ConfirmationsCard {
    sent: number;
    confirmed: number;
    confirmed_share: number | null;
    cancel: number;
    edit: number;
    no_response: number;
    waiting: number;
    needs_call: number;
    links: { sent: string; confirmed: string; requests: string; needs_call: string };
}

export interface ConfirmationSettingsData {
    enabled: boolean;
    delay_minutes: number;
    reminder_after_hours: number;
    no_response_after_hours: number;
    template_confirm: string;
    template_reminder: string;
    template_language: string;
    skip_chat_orders: boolean;
    max_order_age_minutes: number;
}

export interface WhatsAppChannelState {
    name: string | null;
    phone: string | null;
    connected: boolean;
    status: string | null;
}
