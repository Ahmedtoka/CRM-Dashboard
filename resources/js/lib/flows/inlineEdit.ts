import type { InjectionKey } from 'vue';

/** An inline edit in progress on a node, shown live by the customer preview before it is saved. */
export interface InlineDraft {
    stepId: string;
    text?: string;
    option?: { index: number; title: string };
}

/**
 * What FlowCanvas gives its StepNodes for editing on the node (design 2026-09-18 §3). Every
 * commit goes through the same flowGraph updaters as the side panel.
 */
export interface InlineEditContext {
    /** an inline editor opened (true) or closed (false); the canvas turns its keyboard shortcuts off meanwhile */
    setEditing(open: boolean): void;
    /** the live, unsaved value (null clears it) */
    draft(draft: InlineDraft | null): void;
    commitText(stepId: string, text: string): void;
    commitOptionTitle(stepId: string, index: number, title: string): void;
    /** the translated reason `to` cannot be the step's new id, or null */
    renameProblem(stepId: string, to: string): string | null;
    /** @returns false when the rename was refused */
    rename(stepId: string, to: string): boolean;
}

export const INLINE_EDIT: InjectionKey<InlineEditContext> = Symbol('flow-inline-edit');
