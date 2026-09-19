/** Flow designer types (design doc 2026-09-17-flow-designer). Mirrors app/Bot/Flows/FlowDefinition.php. */
import type { OutboundCards } from './crm';

export interface FlowOption {
    title: string;
    /** choice options: the stored answer value */
    value?: string;
    /** menu options: `flow:<key>` | `menu:<key>` | `script:<key>` | `handover`; choice/status options: `flow:<key>` (the verified order carried) | `menu:<key>` | `handover`, instead of `next` */
    action?: string;
    /** choice options may jump to a step directly */
    next?: string;
    /** status options: shown only while the order is on its way / once delivered or cancelled */
    when?: 'open' | 'finished';
    synonyms?: string[];
}

export interface FlowBranch {
    field: string;
    in: string[];
    next: string;
}

export interface FlowStep {
    type: string;
    text?: string;
    field?: string;
    options?: FlowOption[];
    next?: string;
    branches?: FlowBranch[];
    script?: string;
    case_type?: string;
    /** order steps: ask for the last 4 digits of the mobile before revealing the order (spec 2026-09-19 §1) */
    verify_owner?: boolean;
    /** order_items steps: false = a plain picker without the return rules (cancel/edit, 2026-09-19) */
    return_rules?: boolean;
    /** choice steps: a typed answer that is not a button is saved as typed */
    allow_text?: boolean;
    /** text steps: photos sent with the answer are kept (`<field>_photo`) */
    photos?: boolean;
}

/** The on/off switches a step may carry, and the step types that read them (FlowDefinition::FLAGS). */
export type StepFlag = 'verify_owner' | 'return_rules' | 'allow_text' | 'photos';

export interface FlowDefinition {
    start: string;
    steps: Record<string, FlowStep>;
    layout?: Record<string, { x: number; y: number }>;
}

export type StepOptionsKind = 'none' | 'choice' | 'menu' | 'summary';

export interface StepTypeInfo {
    label_ar: string;
    icon: string;
    color: string;
    fields: string[];
    options: StepOptionsKind;
    has_next: boolean;
}

export type StepTypeCatalog = Record<string, StepTypeInfo>;

export interface FlowListRow {
    id: number;
    key: string;
    title_ar: string;
    is_active: boolean;
    has_draft: boolean;
    published_version: number | null;
    in_main_menu: boolean;
}

export interface FlowScriptOption {
    key: string;
    title: string;
    /** the raw body (placeholders unrendered), for the customer preview */
    body?: string;
    /** inactive scripts are never sent (FlowPrompter::script) */
    is_active?: boolean;
}

export interface FlowVersionRow {
    id: number;
    version: number;
    status: string;
    note: string | null;
    created_by: string | null;
    published_at: string | null;
    created_at: string | null;
}

export interface FlowShowPayload {
    flow: { id: number; key: string; title_ar: string; is_active: boolean };
    draft: FlowDefinition | null;
    published: FlowDefinition;
    errors: string[];
    warnings: string[];
    versions: FlowVersionRow[];
    draft_updated_at: string | null;
}

export type SourceHandle = 'next' | `option:${number}` | `branch:${number}`;

export interface StepNodeData {
    stepId: string;
    step: FlowStep;
    info: StepTypeInfo | null;
    isStart: boolean;
    errors: string[];
    /** edge-style labels for each branch row ("لو reason = بايظ") */
    branchLabels: string[];
    highlighted?: boolean;
}

export interface TerminalNodeData {
    kind: 'flow' | 'menu' | 'script' | 'handover' | 'end' | 'unknown';
    label: string;
}

/** The sandbox chat (POST settings/bot-flows/{flow}/simulate); the response is plain JSON, not data-wrapped. */
export type SandboxSource = 'draft' | 'published';

export interface SandboxButton {
    title: string;
    payload: string;
}

export interface SandboxEvent {
    type: 'case' | 'handover' | 'flow_start' | 'exit' | 'question' | 'invalid' | string;
    label: string;
    data: Record<string, unknown>;
}

export interface SandboxResponse {
    messages: { text: string; buttons: SandboxButton[]; cards?: OutboundCards | null }[];
    events: SandboxEvent[];
    state: Record<string, unknown> | null;
    current: { flow_key: string; step_id: string } | null;
}
