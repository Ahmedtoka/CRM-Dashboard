<script setup lang="ts">
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useI18n } from '@/composables/useI18n';
import {
    END_NODE_ID,
    NODE_WIDTH,
    STEP_ID_PROBLEM_KEYS,
    parseEdgeId,
    renameStep,
    setOptionTitle,
    setStepText,
    stepIdProblem,
    toGraph,
    type TerminalLabels,
} from '@/lib/flows/flowGraph';
import { INLINE_EDIT, type InlineDraft } from '@/lib/flows/inlineEdit';
import { PALETTE_DRAG_TYPE } from '@/lib/flows/stepPalette';
import type { FlowDefinition, SourceHandle, StepNodeData, StepTypeCatalog, TerminalNodeData } from '@/types/flows';
import { Background } from '@vue-flow/background';
import { Panel, VueFlow, useVueFlow, type Connection, type EdgeMouseEvent, type Node, type NodeDragEvent, type NodeMouseEvent } from '@vue-flow/core';
import { MiniMap } from '@vue-flow/minimap';
import { Blocks, LayoutDashboard, Map as MapIcon, Maximize, Minus, Plus } from 'lucide-vue-next';
import { computed, nextTick, provide, ref, watch, type Component } from 'vue';
import StepNode from './StepNode.vue';
import TerminalNode from './TerminalNode.vue';

const props = defineProps<{
    /** changes when another flow is opened, so the view re-fits */
    flowKey: string;
    def: FlowDefinition;
    catalog: StepTypeCatalog;
    errorsByStep: Record<string, string[]>;
    labels: TerminalLabels;
    /** a step to ring (the sandbox's current step, Task 5) */
    highlightId?: string | null;
    /** width in px of the drawer covering the canvas's end side, so focusing a step centres it in what is still visible */
    endInset?: number;
}>();

const emit = defineEmits<{
    select: [stepId: string | null];
    connect: [source: { stepId: string; handle: SourceHandle }, targetStepId: string];
    disconnect: [edgeId: string];
    move: [positions: { id: string; x: number; y: number }[]];
    /** an inline edit on a node was saved (same updaters as the side panel) */
    change: [def: FlowDefinition, selectId?: string];
    /** the live value of an inline edit in progress, for the customer preview */
    draft: [draft: InlineDraft | null];
    /** a palette card was dropped on the canvas at this flow position */
    dropStep: [type: string, position: { x: number; y: number }];
    autoLayout: [];
}>();

const { t, dir } = useI18n();
const FLOW_ID = 'flow-designer';
const { fitView, setCenter, setViewport, findNode, screenToFlowCoordinate, zoomIn, zoomOut, viewport, dimensions, onNodesInitialized } =
    useVueFlow(FLOW_ID);

/** The canvas itself is LTR (Vue Flow maths); the controls sit on the page's start corner, away from the drawer. */
const startCorner = computed(() => (dir.value === 'rtl' ? 'bottom-right' : 'bottom-left'));
const tooltipSide = computed(() => (dir.value === 'rtl' ? 'left' : 'right'));

// ---- minimap: off by default so it never covers nodes; the choice is remembered per browser ----
const MINIMAP_KEY = 'bot-flows:minimap';
function readMinimap(): boolean {
    try {
        return window.localStorage.getItem(MINIMAP_KEY) === '1';
    } catch {
        return false;
    }
}
const showMinimap = ref(readMinimap());
watch(showMinimap, (on) => {
    try {
        window.localStorage.setItem(MINIMAP_KEY, on ? '1' : '0');
    } catch {
        // Only a convenience.
    }
});

const isEmpty = computed(() => Object.keys(props.def.steps).length === 0);

const controls = computed<{ id: string; label: string; icon: Component; run: () => void; pressed?: boolean }[]>(() => [
    { id: 'in', label: t('flows.workspace.zoom_in'), icon: Plus, run: () => zoomIn({ duration: 150 }) },
    { id: 'out', label: t('flows.workspace.zoom_out'), icon: Minus, run: () => zoomOut({ duration: 150 }) },
    { id: 'fit', label: t('flows.workspace.fit_view'), icon: Maximize, run: fitAll },
    { id: 'layout', label: t('flows.auto_layout'), icon: LayoutDashboard, run: () => emit('autoLayout') },
    {
        id: 'map',
        label: showMinimap.value ? t('flows.workspace.minimap_hide') : t('flows.workspace.minimap_show'),
        icon: MapIcon,
        run: () => (showMinimap.value = !showMinimap.value),
        pressed: showMinimap.value,
    },
]);

// ---- inline editing on the node (design 2026-09-18 §3) ----------------------
/** While an inline editor is open, keyboard moves of the selected node are off. */
const editing = ref(false);

provide(INLINE_EDIT, {
    setEditing: (open) => (editing.value = open),
    draft: (draft) => emit('draft', draft),
    commitText: (stepId, text) => {
        if ((props.def.steps[stepId]?.text ?? '') !== text) emit('change', setStepText(props.def, stepId, text));
    },
    commitOptionTitle: (stepId, index, title) => {
        if (props.def.steps[stepId]?.options?.[index] && props.def.steps[stepId].options![index].title !== title) {
            emit('change', setOptionTitle(props.def, stepId, index, title));
        }
    },
    renameProblem: (stepId, to) => {
        const problem = stepIdProblem(props.def, stepId, to);
        return problem ? t(STEP_ID_PROBLEM_KEYS[problem]) : null;
    },
    rename: (stepId, to) => {
        if (to === stepId) return true;
        if (stepIdProblem(props.def, stepId, to)) return false;
        emit('change', renameStep(props.def, stepId, to), to);
        return true;
    },
});

// ---- palette drops (design 2026-09-18 §4) -----------------------------------
function onDragOver(event: DragEvent): void {
    if (!event.dataTransfer?.types.includes(PALETTE_DRAG_TYPE)) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'copy';
}

function onDrop(event: DragEvent): void {
    const type = event.dataTransfer?.getData(PALETTE_DRAG_TYPE);
    if (!type) return;
    event.preventDefault();
    const at = screenToFlowCoordinate({ x: event.clientX, y: event.clientY });
    // The pointer holds the card by its middle, so the node is centred on it.
    emit('dropStep', type, { x: at.x - NODE_WIDTH / 2, y: at.y - 40 });
}

const graph = computed(() => {
    const g = toGraph(props.def, props.catalog, props.errorsByStep, props.labels);
    if (props.highlightId) {
        for (const node of g.nodes) {
            if (node.id === props.highlightId && node.type === 'step') node.data = { ...(node.data as StepNodeData), highlighted: true };
        }
    }
    return g;
});

function isStepNode(id: string): boolean {
    return !!props.def.steps[id];
}

function onConnect(connection: Connection): void {
    const { source, sourceHandle, target } = connection;
    if (!sourceHandle || !isStepNode(source)) return;
    if (target !== END_NODE_ID && !isStepNode(target)) return;
    if (sourceHandle !== 'next' && !/^(option|branch):\d+$/.test(sourceHandle)) return;
    emit('connect', { stepId: source, handle: sourceHandle as SourceHandle }, target);
}

function onDragStop(event: NodeDragEvent): void {
    const moved = (event.nodes.length ? event.nodes : [event.node])
        .filter((node: Node) => isStepNode(node.id))
        .map((node: Node) => ({ id: node.id, x: node.position.x, y: node.position.y }));
    if (moved.length) emit('move', moved);
}

function onNodeClick({ node }: NodeMouseEvent): void {
    if (isStepNode(node.id)) emit('select', node.id);
    else if (node.id.startsWith('terminal::')) emit('select', node.id.split('::')[1] ?? null);
}

function onEdgeClick({ edge }: EdgeMouseEvent): void {
    if (!parseEdgeId(edge.id)) return;
    if (window.confirm(t('flows.delete_edge_confirm'))) emit('disconnect', edge.id);
}

/** Below this zoom the node text is too small to read, so opening a long flow starts at its top instead. */
const READABLE_ZOOM = 0.6;
const OPEN_ZOOM = 0.85;

/** The whole flow in view (the fit button). */
function fitAll(): void {
    fitView({ padding: 0.2, duration: 250, maxZoom: 1.1 });
}

/**
 * Opening a flow: fit it when it stays readable; a long flow instead opens at a readable zoom
 * with its start step centred at the top (the fit button still shows the whole flow).
 */
async function openView(): Promise<void> {
    await fitView({ padding: 0.2, maxZoom: 1.1 });
    const start = findNode(props.def.start);
    if (viewport.value.zoom >= READABLE_ZOOM || !start) return;
    const w = start.dimensions.width || NODE_WIDTH;
    setViewport(
        { zoom: OPEN_ZOOM, x: dimensions.value.width / 2 - (start.position.x + w / 2) * OPEN_ZOOM, y: 48 - start.position.y * OPEN_ZOOM },
        { duration: 200 },
    );
}

function fit(): void {
    nextTick(() => window.setTimeout(openView, 60));
}

// The first view waits until Vue Flow has measured the nodes (fitting earlier does nothing).
let firstView = true;
onNodesInitialized(() => {
    if (!firstView) return;
    firstView = false;
    openView();
});

function focusStep(id: string): void {
    const node = findNode(id);
    if (!node) return;
    const w = node.dimensions.width || 260;
    const h = node.dimensions.height || 140;
    // The drawer covers the end side, so the visible middle is shifted toward the start by half its width.
    const shift = (props.endInset ?? 0) / 2;
    const towardEnd = dir.value === 'rtl' ? -1 : 1;
    setCenter(node.position.x + w / 2 + towardEnd * shift, node.position.y + h / 2, { zoom: 1, duration: 350 });
}

watch(() => props.flowKey, fit);

const minimapColor = (node: Node): string => {
    if (node.type === 'terminal') return (node.data as TerminalNodeData).kind === 'end' ? '#334155' : '#cbd5e1';
    return (node.data as StepNodeData).errors.length ? '#ef4444' : '#94a3b8';
};

defineExpose({ fit, focusStep });
</script>

<template>
    <div class="flow-canvas relative h-full w-full" dir="ltr" :aria-label="t('flows.canvas')" role="region" @dragover="onDragOver" @drop="onDrop">
        <VueFlow
            :id="FLOW_ID"
            :nodes="graph.nodes"
            :edges="graph.edges"
            :delete-key-code="null"
            :min-zoom="0.2"
            :max-zoom="1.75"
            :nodes-connectable="true"
            :elevate-edges-on-select="true"
            :connection-radius="30"
            :disable-keyboard-a11y="editing"
            @connect="onConnect"
            @node-drag-stop="onDragStop"
            @node-click="onNodeClick"
            @edge-click="onEdgeClick"
            @pane-click="emit('select', null)"
        >
            <template #node-step="nodeProps">
                <StepNode v-bind="nodeProps" />
            </template>
            <template #node-terminal="nodeProps">
                <TerminalNode v-bind="nodeProps" />
            </template>

            <Background :gap="24" :size="1.4" pattern-color="hsl(var(--muted-foreground) / 0.28)" />

            <Panel :position="startCorner" class="!m-3 flex flex-col gap-2" :class="dir === 'rtl' ? 'items-end' : 'items-start'">
                <MiniMap
                    v-if="showMinimap"
                    class="flow-minimap !static !m-0 hidden md:block"
                    pannable
                    zoomable
                    :width="168"
                    :height="112"
                    :node-color="minimapColor"
                    :node-border-radius="6"
                    :aria-label="t('flows.workspace.minimap_show')"
                />
                <div
                    class="flex flex-col overflow-hidden rounded-lg border border-border bg-card shadow-card"
                    role="toolbar"
                    aria-orientation="vertical"
                    :aria-label="t('flows.workspace.canvas_controls')"
                >
                    <Tooltip v-for="control in controls" :key="control.id">
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                class="flex size-9 items-center justify-center text-muted-foreground outline-none transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring [&:not(:first-child)]:border-t [&:not(:first-child)]:border-border"
                                :class="[control.id === 'map' ? 'hidden md:flex' : '', control.pressed ? 'bg-surface-accent text-primary' : '']"
                                :aria-label="control.label"
                                :aria-pressed="control.pressed"
                                @click="control.run"
                            >
                                <component :is="control.icon" class="size-4" aria-hidden="true" />
                            </button>
                        </TooltipTrigger>
                        <TooltipContent :side="tooltipSide" class="text-xs">{{ control.label }}</TooltipContent>
                    </Tooltip>
                </div>
            </Panel>
        </VueFlow>

        <!-- A flow with no steps yet: point at the rail. -->
        <div v-if="isEmpty" class="pointer-events-none absolute inset-0 flex items-center justify-center p-6" dir="auto">
            <div
                class="flex max-w-sm flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-border bg-card/80 px-8 py-10 text-center backdrop-blur-sm"
            >
                <span class="flex size-12 items-center justify-center rounded-full bg-surface-accent text-primary">
                    <Blocks class="size-5" aria-hidden="true" />
                </span>
                <p class="text-base font-semibold">{{ t('flows.workspace.empty_canvas') }}</p>
                <p class="text-xs leading-5 text-muted-foreground">{{ t('flows.workspace.empty_canvas_hint') }}</p>
            </div>
        </div>
    </div>
</template>

<style>
.flow-canvas .vue-flow__node-step,
.flow-canvas .vue-flow__node-terminal {
    padding: 0;
    border: 0;
    background: transparent;
    box-shadow: none;
}
.flow-canvas .flow-handle {
    width: 11px;
    height: 11px;
    border: 2px solid hsl(var(--card));
    background: hsl(var(--primary));
}
.flow-canvas .flow-handle--in {
    background: hsl(var(--muted-foreground));
}
.flow-canvas .flow-handle--branch {
    background: rgb(217 119 6);
}
.flow-canvas .flow-handle--chip {
    opacity: 0;
    pointer-events: none;
}
.flow-canvas .flow-handle--next {
    width: 14px;
    height: 14px;
}
.flow-canvas .vue-flow__edge-path {
    stroke: hsl(var(--muted-foreground) / 0.7);
    stroke-width: 1.6;
}
.flow-canvas .flow-edge--option .vue-flow__edge-path {
    stroke: hsl(var(--primary));
}
.flow-canvas .flow-edge--branch .vue-flow__edge-path {
    stroke: rgb(217 119 6);
}
.flow-canvas .flow-edge--action .vue-flow__edge-path {
    stroke: rgb(139 92 246 / 0.7);
}
.flow-canvas .vue-flow__edge:hover .vue-flow__edge-path,
.flow-canvas .vue-flow__edge.selected .vue-flow__edge-path {
    stroke-width: 2.6;
}
.flow-canvas .vue-flow__edge-textbg {
    fill: hsl(var(--card));
}
.flow-canvas .vue-flow__edge-text {
    font-size: 11px;
    font-family: inherit;
    fill: hsl(var(--foreground));
}
.flow-canvas .flow-minimap {
    border: 1px solid hsl(var(--border));
    border-radius: 0.5rem;
    overflow: hidden;
    background: hsl(var(--card));
    box-shadow: var(--shadow-card);
}
.flow-canvas .flow-minimap .vue-flow__minimap-mask {
    fill: hsl(var(--muted) / 0.6);
}
</style>
