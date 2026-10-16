import type { Locale } from '@/i18n';
import { formatCount } from '@/lib/format';
import { formatShare, type CardRow, type Translate } from '@/lib/today';
import { SKIP_REASONS, type ConfirmationsCard, type ConfirmationStatus, type ConfirmationTimelineStep } from '@/types/orderConfirmations';

export type ChipTone = 'neutral' | 'positive' | 'warning' | 'negative' | 'info' | 'overdue';

/** One tone per status: green confirmed, red cancel, amber edit, orange no answer, blue waiting on the customer. */
export const CONFIRMATION_TONE: Record<ConfirmationStatus, ChipTone> = {
    scheduled: 'neutral',
    sent: 'info',
    confirmed: 'positive',
    called_confirmed: 'positive',
    edit_requested: 'warning',
    cancel_requested: 'negative',
    called_cancelled: 'negative',
    no_response: 'overdue',
    unreachable: 'overdue',
    skipped: 'neutral',
    failed: 'negative',
};

export function confirmationStatusLabel(t: Translate, status: string): string {
    return t(`order_confirmations.statuses.${status}`);
}

/** A skip reason W1 wrote; an unknown one shows as it came. */
export function skipReasonLabel(t: Translate, reason: string | null | undefined): string {
    if (!reason) return '';
    return (SKIP_REASONS as readonly string[]).includes(reason) ? t(`order_confirmations.skip_reasons.${reason}`) : reason;
}

/** The chip's hover text: why it was skipped, or the send error. */
export function confirmationDetail(t: Translate, row: { status: string; skip_reason: string | null; last_error: string | null }): string {
    if (row.status === 'skipped') return skipReasonLabel(t, row.skip_reason);
    if (row.status === 'failed') return row.last_error ?? '';
    return '';
}

/** A timeline step's line, with its detail: the answer, the call outcome, the skip reason or the error. */
export function timelineLabel(t: Translate, step: ConfirmationTimelineStep): string {
    const label = t(`order_confirmations.timeline.${step.key}`);
    if (!step.detail) return label;
    const detail =
        step.key === 'answered'
            ? t(`order_confirmations.answers.${step.detail}`)
            : step.key === 'called'
              ? t(`order_confirmations.call_outcomes.${step.detail}`)
              : step.key === 'skipped'
                ? skipReasonLabel(t, step.detail)
                : step.detail;

    return `${label}: ${detail}`;
}

/** The «النهارده» card rows; every number opens its tab of «تأكيدات الأوردرات». */
export function confirmationsRows(c: ConfirmationsCard, t: Translate, locale: Locale): CardRow[] {
    const n = (v: number) => formatCount(v, locale);

    return [
        { key: 'sent', label: t('order_confirmations.counters.sent'), value: n(c.sent), href: c.links.sent },
        {
            key: 'confirmed',
            label: t('order_confirmations.counters.confirmed'),
            value: c.confirmed_share === null ? n(c.confirmed) : `${n(c.confirmed)} (${formatShare(c.confirmed_share, locale)})`,
            href: c.links.confirmed,
            tone: 'good',
        },
        {
            key: 'requests',
            label: t('order_confirmations.today.requests'),
            value: t('order_confirmations.today.requests_value', { cancel: n(c.cancel), edit: n(c.edit) }),
            href: c.links.requests,
        },
        { key: 'waiting', label: t('order_confirmations.counters.waiting'), value: n(c.waiting), href: c.links.sent },
        {
            key: 'needs_call',
            label: t('order_confirmations.today.needs_call', { n: n(c.needs_call) }),
            value: n(c.needs_call),
            href: c.links.needs_call,
            tone: c.needs_call > 0 ? 'bad' : 'default',
        },
    ];
}
