import type { Tone } from '@/lib/orderStatus';
import type { CasePriority, CaseStatus, CaseType } from '@/types/crm';

export const caseStatusTone: Record<CaseStatus, Tone | 'brand'> = {
    // Open work reads blue (mockup «مفتوحة»), being worked on brand; amber stays for waiting on someone else.
    new: 'info',
    in_progress: 'brand',
    resolved: 'positive',
    closed: 'neutral',
};

// `normal` / `low` exist on rows written outside the case recorder (older rows, demo data).
export const casePriorityTone: Record<CasePriority, Tone> = {
    low: 'neutral',
    normal: 'neutral',
    medium: 'neutral',
    // Amber, not red: red is kept for the SLA chip beside it (DESIGN §2.1).
    high: 'warning',
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
