import type { FlowDefinition } from '@/types/flows';
import dagre from '@dagrejs/dagre';
import { END_TARGET, NODE_WIDTH, nodeHeight } from './flowGraph';

/** Room kept on a step's left for its menu-action chips (`toGraph` draws them 230px to the left, up to 200px wide). */
const CHIP_LANE = 240;

/**
 * Top-to-bottom dagre layout of a flow's steps (nodesep 100, ranksep 130: room for the edges that
 * leave each option row sideways). Returns a new definition whose `layout` holds each step's
 * top-left position; menu-action chips and the end node are placed relative to it by `toGraph`,
 * so they are never stored — but a step with chips is laid out wider so they never cover a neighbour.
 */
export function layout(def: FlowDefinition): FlowDefinition {
    const g = new dagre.graphlib.Graph();
    g.setGraph({ rankdir: 'TB', nodesep: 100, ranksep: 130 });
    g.setDefaultEdgeLabel(() => ({}));

    const ids = Object.keys(def.steps);
    const chipLane = (id: string): number =>
        (def.steps[id].options ?? []).some((o) => o.action !== undefined && o.next === undefined) ? CHIP_LANE : 0;
    for (const id of ids) g.setNode(id, { width: NODE_WIDTH + chipLane(id), height: nodeHeight(def.steps[id]) });

    for (const id of ids) {
        const step = def.steps[id];
        const targets = [step.next, ...(step.branches ?? []).map((b) => b.next), ...(step.options ?? []).map((o) => o.next)];
        for (const target of targets) {
            if (target && target !== END_TARGET && def.steps[target] && target !== id) g.setEdge(id, target);
        }
    }

    dagre.layout(g);

    const positions: Record<string, { x: number; y: number }> = {};
    for (const id of ids) {
        const node = g.node(id);
        // The step sits at the right of its box; the chip lane is the box's left part.
        positions[id] = { x: Math.round(node.x + node.width / 2 - NODE_WIDTH), y: Math.round(node.y - node.height / 2) };
    }

    return { ...JSON.parse(JSON.stringify(def)), layout: positions } as FlowDefinition;
}
