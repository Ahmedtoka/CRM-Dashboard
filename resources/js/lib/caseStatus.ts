import type { Tone } from '@/lib/orderStatus';
import type { CasePriority, CaseStatus } from '@/types/crm';

export const caseStatusTone: Record<CaseStatus, Tone> = {
    new: 'warning',
    in_progress: 'info',
    closed: 'positive',
};

// `normal` / `low` exist on rows written outside the case recorder (older rows, demo data).
export const casePriorityTone: Record<CasePriority, Tone> = {
    low: 'neutral',
    normal: 'neutral',
    medium: 'neutral',
    high: 'negative',
};
