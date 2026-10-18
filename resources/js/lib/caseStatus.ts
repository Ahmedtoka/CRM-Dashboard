import type { Tone } from '@/lib/orderStatus';
import type { CasePriority, CaseStatus, CaseType } from '@/types/crm';

export const caseStatusTone: Record<CaseStatus, Tone> = {
    new: 'warning',
    in_progress: 'info',
    resolved: 'positive',
    closed: 'neutral',
};

// `normal` / `low` exist on rows written outside the case recorder (older rows, demo data).
export const casePriorityTone: Record<CasePriority, Tone> = {
    low: 'neutral',
    normal: 'neutral',
    medium: 'neutral',
    high: 'negative',
};

/** One tone per case type (mockup «ما بعد البيع»): a return reads amber, an exchange brand, a complaint red. */
export const caseTypeTone: Record<CaseType, 'warn' | 'brand' | 'danger' | 'info' | 'neutral'> = {
    return: 'warn',
    return_exchange: 'warn',
    exchange: 'brand',
    complaint: 'danger',
    delivery_followup: 'info',
    cancel_edit: 'neutral',
};
