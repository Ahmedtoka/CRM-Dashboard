/**
 * Pure, framework-free helpers for the flow designer (design doc 2026-09-17 §4):
 * FlowDefinition <-> Vue Flow nodes/edges, and the reference-safe edits the canvas
 * and step editor perform. Every edit returns a new definition (inputs are never mutated).
 *
 * Ids: step nodes use the step id; the end node is `__end__`; menu-action chips are
 * `terminal::<stepId>::<optionIndex>`; edges are `<stepId>::<handle>` where handle is
 * `next` | `option:<i>` | `branch:<i>`.
 */
import type { FlowDefinition, FlowOption, FlowStep, SourceHandle, StepNodeData, StepTypeCatalog, TerminalNodeData } from '@/types/flows';
import type { Edge, Node } from '@vue-flow/core';
import { MarkerType } from '@vue-flow/core';

export const END_NODE_ID = '__end__';
export const END_TARGET = 'end';
export const STEP_ID_PATTERN = /^[a-z0-9_]{1,40}$/;

/** `end` is the flow-end target, so no step may take that id (FlowDefinition rejects it too). */
export const isReservedStepId = (id: string): boolean => id === END_TARGET;
export const isValidStepId = (id: string): boolean => STEP_ID_PATTERN.test(id) && !isReservedStepId(id);

export const NODE_WIDTH = 260;
export const nodeHeight = (step: FlowStep): number => 120 + 24 * (step.options?.length ?? 0);

/** Rough line count of a node's message (StepNode clamps it to 3 lines of ~44 characters). */
function textLines(text: string | undefined): number {
    if (!text) return 1;
    const lines = text.split('\n').reduce((sum, part) => sum + Math.max(1, Math.ceil([...part].length / 44)), 0);
    return Math.min(3, lines);
}

/**
 * The top of a menu-action chip, level with option row `index` of its step (StepNode: 6px strip,
 * 46px header, 20px per text line, then 28px per option row), so the edge to it runs straight across.
 */
const chipTop = (step: FlowStep, y: number, index: number): number => y + 67 + 20 * textLines(step.text) + 28 * index;

export interface TerminalLabels {
    flow: (key: string) => string;
    menu: (key: string) => string;
    script: (key: string) => string;
    handover: string;
    end: string;
    branch: (condition: string) => string;
}

export const DEFAULT_TERMINAL_LABELS: TerminalLabels = {
    flow: (key) => `↗ فلو: ${key}`,
    menu: (key) => `☰ قائمة: ${key}`,
    script: (key) => `📄 سكريبت: ${key}`,
    handover: '👤 موظف',
    end: 'النهاية',
    branch: (condition) => `لو ${condition}`,
};

const clone = (def: FlowDefinition): FlowDefinition => JSON.parse(JSON.stringify(def)) as FlowDefinition;

export const edgeId = (stepId: string, handle: SourceHandle): string => `${stepId}::${handle}`;
export const terminalId = (stepId: string, index: number): string => `terminal::${stepId}::${index}`;

export function parseEdgeId(id: string): { stepId: string; handle: SourceHandle } | null {
    const at = id.lastIndexOf('::');
    if (at <= 0) return null;
    const stepId = id.slice(0, at);
    const handle = id.slice(at + 2);
    if (handle === 'next' || /^(option|branch):\d+$/.test(handle)) return { stepId, handle: handle as SourceHandle };
    return null;
}

function parseHandle(handle: SourceHandle): { kind: 'next' } | { kind: 'option' | 'branch'; index: number } {
    if (handle === 'next') return { kind: 'next' };
    const [kind, index] = handle.split(':');
    return { kind: kind as 'option' | 'branch', index: Number(index) };
}

/** A menu option action split into its kind and key (`handover` has no key). */
export function parseAction(action: string | undefined): { kind: TerminalNodeData['kind']; key: string } {
    if (!action) return { kind: 'unknown', key: '' };
    if (action === 'handover') return { kind: 'handover', key: '' };
    const colon = action.indexOf(':');
    const kind = colon > 0 ? action.slice(0, colon) : '';
    const key = colon > 0 ? action.slice(colon + 1) : action;
    return kind === 'flow' || kind === 'menu' || kind === 'script' ? { kind, key } : { kind: 'unknown', key: action };
}

const targetNode = (target: string): string => (target === END_TARGET ? END_NODE_ID : target);

export function toGraph(
    def: FlowDefinition,
    catalog: StepTypeCatalog,
    errorsByStep: Record<string, string[]>,
    labels: TerminalLabels = DEFAULT_TERMINAL_LABELS,
): { nodes: Node[]; edges: Edge[] } {
    const nodes: Node[] = [];
    const edges: Edge[] = [];
    const ids = Object.keys(def.steps);
    const exists = (target: string | undefined): target is string => !!target && (target === END_TARGET || ids.includes(target));

    let maxBottom = 0;
    let sumX = 0;

    ids.forEach((id, i) => {
        const step = def.steps[id];
        const position = def.layout?.[id] ?? { x: (i % 3) * (NODE_WIDTH + 60), y: Math.floor(i / 3) * 260 };
        maxBottom = Math.max(maxBottom, position.y + nodeHeight(step));
        sumX += position.x;

        const data: StepNodeData = {
            stepId: id,
            step,
            info: catalog[step.type] ?? null,
            isStart: def.start === id,
            errors: errorsByStep[id] ?? [],
            branchLabels: (step.branches ?? []).map((b) => labels.branch(branchLabel(def, b.field, b.in))),
        };
        nodes.push({ id, type: 'step', position: { x: position.x, y: position.y }, data });

        const kind = catalog[step.type]?.options ?? 'none';

        // Edges carry no text: each one leaves from its own option / branch row, which already names it.
        (step.options ?? []).forEach((option, index) => {
            const handle: SourceHandle = `option:${index}`;
            if (kind === 'menu' || (option.action !== undefined && option.next === undefined)) {
                if (!option.action) return;
                const { kind: actionKind, key } = parseAction(option.action);
                const tid = terminalId(id, index);
                const label =
                    actionKind === 'flow'
                        ? labels.flow(key)
                        : actionKind === 'menu'
                          ? labels.menu(key)
                          : actionKind === 'script'
                            ? labels.script(key)
                            : actionKind === 'handover'
                              ? labels.handover
                              : option.action;
                const tdata: TerminalNodeData = { kind: actionKind, label };
                nodes.push({
                    id: tid,
                    type: 'terminal',
                    position: { x: position.x - 230, y: chipTop(step, position.y, index) },
                    data: tdata,
                    connectable: false,
                    draggable: false,
                });
                edges.push({
                    id: edgeId(id, handle),
                    source: id,
                    sourceHandle: handle,
                    target: tid,
                    type: 'smoothstep',
                    class: 'flow-edge flow-edge--action',
                    markerEnd: MarkerType.ArrowClosed,
                });
                return;
            }
            if (exists(option.next)) {
                edges.push({
                    id: edgeId(id, handle),
                    source: id,
                    sourceHandle: handle,
                    target: targetNode(option.next),
                    type: 'smoothstep',
                    class: 'flow-edge flow-edge--option',
                    markerEnd: MarkerType.ArrowClosed,
                });
            }
        });

        (step.branches ?? []).forEach((branch, index) => {
            if (!exists(branch.next)) return;
            edges.push({
                id: edgeId(id, `branch:${index}`),
                source: id,
                sourceHandle: `branch:${index}`,
                target: targetNode(branch.next),
                type: 'smoothstep',
                class: 'flow-edge flow-edge--branch',
                animated: false,
                style: { strokeDasharray: '6 4' },
                markerEnd: MarkerType.ArrowClosed,
            });
        });

        if (exists(step.next)) {
            edges.push({
                id: edgeId(id, 'next'),
                source: id,
                sourceHandle: 'next',
                target: targetNode(step.next),
                type: 'smoothstep',
                class: 'flow-edge flow-edge--next',
                markerEnd: MarkerType.ArrowClosed,
            });
        }
    });

    const endData: TerminalNodeData = { kind: 'end', label: labels.end };
    nodes.push({
        id: END_NODE_ID,
        type: 'terminal',
        position: { x: ids.length ? sumX / ids.length + NODE_WIDTH / 2 - 60 : 0, y: maxBottom + 90 },
        data: endData,
        draggable: false,
    });

    return { nodes, edges };
}

/** "field = title1، title2" using the choice option titles when the field belongs to a choice step. */
export function branchLabel(def: FlowDefinition, field: string, values: string[]): string {
    const titles = values.map((value) => choiceValues(def, field).find((o) => o.value === value)?.title ?? value);
    return `${field || '?'} = ${titles.join('، ') || '…'}`;
}

/** Every field name collected by a step in this definition. */
export function fieldsOf(def: FlowDefinition): string[] {
    return [
        ...new Set(
            Object.values(def.steps)
                .map((s) => s.field)
                .filter((f): f is string => !!f),
        ),
    ];
}

/** The `{value, title}` pairs of the choice step(s) that collect `field`. */
export function choiceValues(def: FlowDefinition, field: string): { value: string; title: string }[] {
    const out: { value: string; title: string }[] = [];
    for (const step of Object.values(def.steps)) {
        if (step.field !== field) continue;
        for (const option of step.options ?? []) {
            if (option.value !== undefined && !out.some((o) => o.value === option.value)) out.push({ value: option.value, title: option.title });
        }
    }
    return out;
}

export function connect(def: FlowDefinition, source: { stepId: string; handle: SourceHandle }, targetStepId: string): FlowDefinition {
    const out = clone(def);
    const step = out.steps[source.stepId];
    if (!step) return def;
    const target = targetStepId === END_NODE_ID ? END_TARGET : targetStepId;
    if (target !== END_TARGET && !out.steps[target]) return def;

    const handle = parseHandle(source.handle);
    if (handle.kind === 'next') {
        step.next = target;
    } else if (handle.kind === 'option') {
        const option = step.options?.[handle.index];
        if (!option) return def;
        option.next = target;
    } else {
        const branch = step.branches?.[handle.index];
        if (!branch) return def;
        branch.next = target;
    }
    return out;
}

export function disconnect(def: FlowDefinition, id: string): FlowDefinition {
    const parsed = parseEdgeId(id);
    if (!parsed) return def;
    const out = clone(def);
    const step = out.steps[parsed.stepId];
    if (!step) return def;

    const handle = parseHandle(parsed.handle);
    if (handle.kind === 'next') {
        step.next = '';
    } else if (handle.kind === 'option') {
        const option = step.options?.[handle.index];
        if (!option) return def;
        if (option.next !== undefined) delete option.next;
        else if (option.action !== undefined) option.action = '';
    } else {
        const branch = step.branches?.[handle.index];
        if (!branch) return def;
        branch.next = '';
    }
    return out;
}

function mapTargets(def: FlowDefinition, fn: (target: string) => string): void {
    for (const step of Object.values(def.steps)) {
        if (step.next !== undefined) step.next = fn(step.next);
        for (const branch of step.branches ?? []) branch.next = fn(branch.next);
        for (const option of step.options ?? []) if (option.next !== undefined) option.next = fn(option.next);
    }
}

export function renameStep(def: FlowDefinition, from: string, to: string): FlowDefinition {
    if (from === to || !def.steps[from] || def.steps[to]) return def;
    const out = clone(def);
    // Rebuild the map so the renamed step keeps its place in the order.
    out.steps = Object.fromEntries(Object.entries(out.steps).map(([id, step]) => [id === from ? to : id, step]));
    if (out.start === from) out.start = to;
    mapTargets(out, (target) => (target === from ? to : target));
    if (out.layout?.[from]) {
        out.layout = Object.fromEntries(Object.entries(out.layout).map(([id, pos]) => [id === from ? to : id, pos]));
    }
    return out;
}

export function deleteStep(def: FlowDefinition, id: string): FlowDefinition {
    if (!def.steps[id]) return def;
    const out = clone(def);
    delete out.steps[id];
    if (out.start === id) out.start = '';
    mapTargets(out, (target) => (target === id ? '' : target));
    if (out.layout) delete out.layout[id];
    return out;
}

// ---- shared step updaters (side panel and inline editing on the node, design 2026-09-18 §3) ----

/** Messenger quick-reply titles are cut at 20 characters; FlowDefinition rejects longer ones. */
export const MAX_OPTION_TITLE = 20;
/** Messenger shows at most 13 quick replies; FlowDefinition allows 1..13 options. */
export const MAX_QUICK_REPLIES = 13;

/** A copy of `def` with `mutate` applied to one step; `def` itself is returned when the step is missing. */
export function updateStep(def: FlowDefinition, id: string, mutate: (step: FlowStep) => void): FlowDefinition {
    if (!def.steps[id]) return def;
    const out = clone(def);
    mutate(out.steps[id]);
    return out;
}

export function setStepText(def: FlowDefinition, id: string, text: string): FlowDefinition {
    return updateStep(def, id, (step) => {
        step.text = text;
    });
}

/** The options list with one title replaced (used by OptionsEditor and `setOptionTitle`). */
export function withOptionTitle(options: FlowOption[], index: number, title: string): FlowOption[] {
    return options.map((option, i) => (i === index ? { ...option, title } : option));
}

export function setOptionTitle(def: FlowDefinition, id: string, index: number, title: string): FlowDefinition {
    if (!def.steps[id]?.options?.[index]) return def;
    return updateStep(def, id, (step) => {
        step.options = withOptionTitle(step.options ?? [], index, title);
    });
}

export type StepIdProblem = 'invalid' | 'reserved' | 'taken';

/** Why `to` cannot replace `from` as a step id (same rules as FlowDefinition), or null when it can (or is unchanged). */
export function stepIdProblem(def: FlowDefinition, from: string, to: string): StepIdProblem | null {
    if (to === from) return null;
    if (isReservedStepId(to)) return 'reserved';
    if (!STEP_ID_PATTERN.test(to)) return 'invalid';
    if (def.steps[to]) return 'taken';
    return null;
}

/** The i18n key for a rename problem (shared by the side panel and the inline editor). */
export const STEP_ID_PROBLEM_KEYS: Record<StepIdProblem, string> = {
    invalid: 'flows.step_id_invalid',
    reserved: 'flows.step_id_reserved',
    taken: 'flows.step_id_taken',
};

// ---- ready-made steps (design 2026-09-18 §4) --------------------------------------------------

/** Placeholder message text per step type for a step added from the palette (the owner edits it). */
export const DEFAULT_STEP_TEXT: Record<string, string> = {
    menu: 'اختاري اللي محتاجاه:',
    choice: 'اكتبي سؤالك هنا',
    text: 'اكتبي سؤالك هنا',
    name: 'ممكن اسمك؟',
    phone: 'ممكن رقم موبايلك؟',
    photo: 'ممكن تبعتيلنا صورة؟',
    order: 'ممكن رقم الأوردر؟',
    order_items: 'اختاري القطعة اللي عايزة ترجعيها أو تبدليها 👇',
    product_link: 'ابعتيلي لينك المنتج اللي عايزة تبدلي بيه من الموقع 🔗',
    branch: 'أنهي فرع؟',
    branches_list: 'دي فروعنا:',
    summary: 'راجعي بياناتك:',
    // Empty: a new record_case step sends its script; the owner may write her own closing text instead.
    record_case: '',
};

/** Field names FlowPrompter already labels, so the summary shows them; used when the flow does not use them yet. */
export const DEFAULT_STEP_FIELD: Record<string, string> = {
    name: 'name',
    phone: 'phone',
    order: 'order_number',
    branch: 'branch_name',
    photo: 'product_photo',
    product_link: 'exchange_product',
};

/** First unused `<type>_<n>` id. */
export function nextStepId(def: FlowDefinition, type: string): string {
    let n = 1;
    while (def.steps[`${type}_${n}`]) n++;
    return `${type}_${n}`;
}

/**
 * A valid default step of `type` (placeholder text, field, two options for choice/menu,
 * `next: 'end'`), merged with `preset` (e.g. `{ case_type: 'complaint' }` or a script key).
 * Menu options keep an empty action for the owner to pick.
 */
export function defaultStep(def: FlowDefinition, type: string, id: string, catalog: StepTypeCatalog, preset: Partial<FlowStep> = {}): FlowStep {
    const info = catalog[type];
    const step: FlowStep = { type };
    if (info?.fields.includes('text')) step.text = DEFAULT_STEP_TEXT[type] ?? info.label_ar;
    if (info?.fields.includes('field')) {
        const wanted = DEFAULT_STEP_FIELD[type];
        step.field = wanted && !fieldsOf(def).includes(wanted) ? wanted : id;
    }
    if (info?.options === 'choice') {
        step.options = [
            { title: 'اختيار ١', value: 'option_1', synonyms: [] },
            { title: 'اختيار ٢', value: 'option_2', synonyms: [] },
        ];
    }
    if (info?.options === 'menu') {
        step.options = [
            { title: 'اختيار ١', action: '', synonyms: [] },
            { title: 'اختيار ٢', action: '', synonyms: [] },
        ];
    }
    if (type === 'record_case') step.case_type = 'complaint';
    if (info?.has_next) step.next = END_TARGET;
    return { ...step, ...JSON.parse(JSON.stringify(preset)) };
}

export interface InsertStepOptions {
    /** the selected step: the new step is placed below it and auto-connected to it */
    after?: string | null;
    /** where to put the node (a canvas drop); defaults to below `after`, else under the lowest node */
    position?: { x: number; y: number };
    preset?: Partial<FlowStep>;
}

export interface InsertStepResult {
    def: FlowDefinition;
    id: string;
    /** the step whose `next` / option now points at the new step, or null when nothing was connected */
    connectedFrom: string | null;
    connectedHandle: SourceHandle | null;
}

/**
 * Adds a default step and auto-connects it to `after` (design 2026-09-18 §4):
 * - a choice step: its first option without its own `next` jumps to the new step; when every
 *   option is connected nothing is connected;
 * - a menu step: its options carry actions, never steps, so nothing is connected;
 * - a step with `next` (handover/end have none): the new step becomes its `next`, and the new
 *   step takes over the old target so the chain is kept (an insert in between).
 * The first step of an empty flow becomes `start`.
 */
export function insertStep(def: FlowDefinition, type: string, catalog: StepTypeCatalog, options: InsertStepOptions = {}): InsertStepResult {
    const out = clone(def);
    const id = nextStepId(out, type);
    const step = defaultStep(out, type, id, catalog, options.preset);
    out.steps[id] = step;
    if (!out.start || !out.steps[out.start]) out.start = id;

    let connectedFrom: string | null = null;
    let connectedHandle: SourceHandle | null = null;
    const after = options.after && options.after !== id ? out.steps[options.after] : undefined;

    if (after && options.after) {
        const kind = catalog[after.type]?.options ?? 'none';
        if (kind === 'choice') {
            const index = (after.options ?? []).findIndex((o) => !o.next);
            if (index >= 0) {
                after.options![index].next = id;
                connectedHandle = `option:${index}`;
            }
        } else if (kind !== 'menu' && (catalog[after.type]?.has_next || after.next !== undefined)) {
            const previous = after.next;
            after.next = id;
            if (step.next !== undefined && previous && (previous === END_TARGET || out.steps[previous])) step.next = previous;
            connectedHandle = 'next';
        }
        if (connectedHandle) connectedFrom = options.after;
    }

    out.layout = { ...(out.layout ?? {}), [id]: freeSpot(out, id, options.position ?? defaultPosition(out, id, options.after ?? null)) };
    return { def: out, id, connectedFrom, connectedHandle };
}

function defaultPosition(def: FlowDefinition, id: string, after: string | null): { x: number; y: number } {
    const at = after ? def.layout?.[after] : undefined;
    if (at && after) return { x: at.x, y: at.y + nodeHeight(def.steps[after]) + 90 };
    const others = Object.entries(def.layout ?? {})
        .filter(([key]) => key !== id)
        .map(([, p]) => p);
    if (!others.length) return { x: 0, y: 0 };
    return { x: Math.min(...others.map((p) => p.x)), y: Math.max(...others.map((p) => p.y)) + 220 };
}

/** Nudges a position sideways until it does not sit on top of another node. */
function freeSpot(def: FlowDefinition, id: string, wanted: { x: number; y: number }): { x: number; y: number } {
    const others = Object.entries(def.layout ?? {})
        .filter(([key]) => key !== id)
        .map(([, p]) => p);
    let { x, y } = { x: Math.round(wanted.x), y: Math.round(wanted.y) };
    for (let guard = 0; guard < 20 && others.some((p) => Math.abs(p.x - x) < NODE_WIDTH - 20 && Math.abs(p.y - y) < 100); guard++) {
        x += NODE_WIDTH + 40;
    }
    return { x, y };
}

export function setPosition(def: FlowDefinition, id: string, x: number, y: number): FlowDefinition {
    if (!def.steps[id]) return def;
    const out = clone(def);
    out.layout = { ...(out.layout ?? {}), [id]: { x: Math.round(x), y: Math.round(y) } };
    return out;
}

/**
 * Groups validator messages by the step they are about: the first `step '<id>'` (or
 * `layout for step '<id>'`, or the Arabic warning `الخطوة <id>`) naming an existing step.
 */
export function errorsByStep(errors: string[], def: FlowDefinition): Record<string, string[]> {
    const out: Record<string, string[]> = {};
    for (const message of errors) {
        const id = stepOfMessage(message, def);
        if (id) (out[id] ??= []).push(message);
    }
    return out;
}

export function stepOfMessage(message: string, def: FlowDefinition): string | null {
    const match = message.match(/step (?:id )?'([^']+)'/) ?? message.match(/الخطوة (\S+)/);
    return match && def.steps[match[1]] ? match[1] : null;
}

type Translate = (key: string, params?: Record<string, string | number>) => string;

/**
 * Turns a FlowDefinition validator message (English, app/Bot/Flows/FlowDefinition.php)
 * into owner-friendly text via `flows.err.*`; unknown shapes are returned unchanged.
 */
export function describeError(message: string, t: Translate): string {
    const target = (label: string): string => {
        const m = label.match(/^(option|branch) #(\d+) next$/);
        if (!m) return t('flows.err.target_next');
        return t(`flows.err.target_${m[1]}`, { n: Number(m[2]) + 1 });
    };
    const rules: [RegExp, (m: RegExpMatchArray) => string][] = [
        [/^definition must have a non-empty 'steps' map$/, () => t('flows.err.no_steps')],
        [/^definition must have a 'start' step key$/, () => t('flows.err.no_start')],
        [/^start step '([^']*)' not found in steps$/, (m) => t('flows.err.start_missing', { step: m[1] })],
        [/^step id '([^']*)' must match/, (m) => t('flows.err.bad_id', { step: m[1] })],
        [/^step id 'end' is reserved$/, () => t('flows.err.reserved_id')],
        [/^step '([^']*)' has an unknown type$/, (m) => t('flows.err.unknown_type', { step: m[1] })],
        [/^step '([^']*)' (.+) must be a non-empty string$/, (m) => t('flows.err.not_connected', { step: m[1], target: target(m[2]) })],
        [
            /^step '([^']*)' (.+) references unknown step '([^']*)'$/,
            (m) => t('flows.err.unknown_step', { step: m[1], target: target(m[2]), ref: m[3] }),
        ],
        [/^step '([^']*)' of type '[^']*' requires a 'field'$/, (m) => t('flows.err.field_required', { step: m[1] })],
        [/^step '([^']*)' requires a 'case_type'/, (m) => t('flows.err.case_type_required', { step: m[1] })],
        [/^step '([^']*)' of type 'script' requires a 'script' key$/, (m) => t('flows.err.script_required', { step: m[1] })],
        [/^step '([^']*)' of type '[^']*' must have between 1 and 13 options$/, (m) => t('flows.err.options_count', { step: m[1] })],
        [/^step '([^']*)' option #(\d+) requires a 'title'/, (m) => t('flows.err.option_title', { step: m[1], n: Number(m[2]) + 1 })],
        [/^step '([^']*)' option #(\d+) requires a 'value'$/, (m) => t('flows.err.option_value', { step: m[1], n: Number(m[2]) + 1 })],
        [
            /^step '([^']*)' option #(\d+) repeats the value '(.*)'$/,
            (m) => t('flows.err.option_value_duplicate', { step: m[1], n: Number(m[2]) + 1, ref: m[3] }),
        ],
        [/^step '([^']*)' option #(\d+) requires an 'action'$/, (m) => t('flows.err.option_action', { step: m[1], n: Number(m[2]) + 1 })],
        [/^step '([^']*)' option #(\d+) 'synonyms'/, (m) => t('flows.err.option_synonyms', { step: m[1], n: Number(m[2]) + 1 })],
        [/^step '([^']*)' branch #(\d+) requires a 'field'$/, (m) => t('flows.err.branch_field', { step: m[1], n: Number(m[2]) + 1 })],
        [/^step '([^']*)' branch #(\d+) requires an 'in' array$/, (m) => t('flows.err.branch_values', { step: m[1], n: Number(m[2]) + 1 })],
        [/^step '([^']*)' references unknown script '([^']*)'$/, (m) => t('flows.err.unknown_script', { step: m[1], ref: m[2] })],
        [
            /^step '([^']*)' option #(\d+) references unknown (flow|script) '([^']*)'$/,
            (m) => t(`flows.err.option_unknown_${m[3]}`, { step: m[1], n: Number(m[2]) + 1, ref: m[4] }),
        ],
        [/^step '([^']*)' 'text' must be a string$/, (m) => t('flows.err.text_string', { step: m[1] })],
        [/^step '([^']*)' 'verify_owner' must be true or false$/, (m) => t('flows.err.verify_owner', { step: m[1] })],
        [/^layout /, () => t('flows.err.layout')],
        [/^الزرار «(.*)» بيوديكي لفلو مش شغال: (\S+)$/, (m) => t('flows.err.option_inactive_flow', { title: m[1], ref: m[2] })],
    ];
    for (const [pattern, render] of rules) {
        const m = message.match(pattern);
        if (m) return render(m);
    }
    return message;
}
