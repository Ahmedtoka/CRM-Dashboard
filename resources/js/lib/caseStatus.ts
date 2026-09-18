import type { Tone } from '@/lib/orderStatus';
import type { CasePriority, CaseStatus } from '@/types/crm';

export const caseStatusTone: Record<CaseStatus, Tone> = {
    new: 'warning',
    in_progress: 'info',
    closed: 'positive',
};

export const casePriorityTone: Record<CasePriority, Tone> = {
    medium: 'neutral',
    high: 'negative',
};
