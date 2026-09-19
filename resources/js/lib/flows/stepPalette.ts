import type { FlowStep } from '@/types/flows';

/** The drag data type a palette card carries onto the canvas (the value is the step type). */
export const PALETTE_DRAG_TYPE = 'application/x-flow-step-type';

export interface PaletteCard {
    type: string;
    /** i18n key suffix under `flows.palette.cards` */
    key: string;
    preset?: Partial<FlowStep>;
}

/** The ready-made step cards, in the order of the design table (2026-09-18 §4). */
export const PALETTE_CARDS: PaletteCard[] = [
    { type: 'choice', key: 'choice' },
    { type: 'menu', key: 'menu' },
    { type: 'text', key: 'text' },
    { type: 'name', key: 'name' },
    { type: 'phone', key: 'phone' },
    { type: 'order', key: 'order' },
    { type: 'order_items', key: 'order_items' },
    { type: 'product_link', key: 'product_link' },
    { type: 'photo', key: 'photo' },
    { type: 'branch', key: 'branch' },
    { type: 'branches_list', key: 'branches_list' },
    { type: 'status', key: 'status' },
    { type: 'summary', key: 'summary' },
    { type: 'record_case', key: 'record_case', preset: { case_type: 'complaint' } },
    { type: 'script', key: 'script' },
    { type: 'handover', key: 'handover' },
    { type: 'end', key: 'end' },
];
