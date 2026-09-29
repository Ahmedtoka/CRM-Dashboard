/**
 * The geometry of the live board's room: one fixed stage that is scaled to the screen, as in the
 * approved simulator (docs/design/queue-routing-simulator.html). Everything here is in stage
 * pixels; nothing reads the DOM.
 */

export const STAGE_W = 1280;
export const STAGE_H = 760;

/** The carpet the moderators' desks stand on. */
export const CARPET = { x: 16, y: 258, w: 888, h: 488 };

export const LEADER = { x: 490, y: 18, w: 300, h: 236 };
export const RECEPTION = { x: 916, y: 20, w: 348, h: 182 };
export const LOUNGE = { x: 916, y: 208, w: 348, h: 536 };

/** A desk cell at full size (the chair, the moderator, her desk and the row of windows). */
export const CELL = { w: 210, h: 244, gapX: 10, gapY: 2 };
export const SLOT = { w: 58, h: 64, gap: 8 };

export const MAX_DESKS = 12;
export const CARDS = 12;
export const HEADS = 42;
export const SEATS = CARDS + HEADS;

/** Where reception figures stand, left to right. */
export const RECEPTION_SPOTS = 6;

export interface DeskBox {
    x: number;
    y: number;
    scale: number;
}

export interface Point {
    x: number;
    y: number;
}

/**
 * Any number of desks from 1 to 12 on the carpet: the grid (columns × rows) that keeps the
 * desks largest, centred, never larger than life. Four desks a row up to eight, as approved;
 * beyond that the cells shrink together.
 */
export function deskLayout(count: number): DeskBox[] {
    const n = Math.max(0, Math.min(MAX_DESKS, Math.floor(count)));
    if (n === 0) return [];

    const pad = 8;
    const availW = CARPET.w - pad * 2;
    const availH = CARPET.h - pad * 2 - 6; // the carpet's caption line
    const stepX = CELL.w + CELL.gapX;
    const stepY = CELL.h + CELL.gapY;

    let best = { cols: 1, rows: n, scale: 0 };
    for (let cols = 1; cols <= n; cols++) {
        const rows = Math.ceil(n / cols);
        const scale = Math.min(1, availW / (cols * stepX - CELL.gapX), availH / (rows * stepY - CELL.gapY));
        // Prefer the larger desks; with equal size, the wider room (fewer rows).
        if (scale > best.scale + 0.001) best = { cols, rows, scale };
    }

    const { cols, rows, scale } = best;
    const gridH = (rows * stepY - CELL.gapY) * scale;
    const top = CARPET.y + pad + 6 + Math.max(0, (availH - gridH) / 2);

    return Array.from({ length: n }, (_, i) => {
        const row = Math.floor(i / cols);
        const inRow = row === rows - 1 ? n - cols * (rows - 1) : cols;
        const rowW = (inRow * stepX - CELL.gapX) * scale;
        const left = CARPET.x + pad + Math.max(0, (availW - rowW) / 2);

        return { x: left + (i % cols) * stepX * scale, y: top + row * stepY * scale, scale };
    });
}

/** The row of windows shrinks as one when a moderator has more windows than fit her desk. */
export function slotScale(count: number, deskWidth: number): number {
    const n = Math.max(1, count);

    return Math.min(1, (deskWidth - 12) / (n * SLOT.w + (n - 1) * SLOT.gap));
}

/** Centre of window `index` (0-based) of a desk with `count` windows, in stage pixels. */
export function slotCentre(box: DeskBox, index: number, count: number, leader = false): Point {
    const width = leader ? LEADER.w : CELL.w;
    const top = leader ? 168 : 2;
    const s = slotScale(count, width);
    const offset = (index - (count - 1) / 2) * (SLOT.w + SLOT.gap) * s;

    return { x: box.x + (width / 2 + offset) * box.scale, y: box.y + (top + (SLOT.h * s) / 2) * box.scale };
}

/** Top-left of lounge card `index`, relative to the lounge. */
export function cardSpot(index: number): Point {
    return { x: 10 + (index % 2) * 172, y: 48 + Math.floor(index / 2) * 66 };
}

/** Centre of lounge seat `index` (a card, then a head), in stage pixels. */
export function seatCentre(index: number): Point {
    if (index < 0) return { x: LOUNGE.x + LOUNGE.w / 2, y: LOUNGE.y + LOUNGE.h - 40 };

    if (index < CARDS) {
        const c = cardSpot(index);

        return { x: LOUNGE.x + c.x + 141, y: LOUNGE.y + c.y + 32 };
    }

    const j = Math.min(index, SEATS - 1) - CARDS;

    return { x: LOUNGE.x + 10 + (j % 14) * 23 + 10, y: LOUNGE.y + 446 + Math.floor(j / 14) * 30 + 14 };
}

/**
 * How much the stage is scaled to fit a box. Zero or unknown sizes (a hidden tab, a wrapper
 * that is not laid out yet) keep the previous scale instead of collapsing the room.
 */
export function stageScale(width: number, height: number | null, previous = 1): number {
    if (!Number.isFinite(width) || width <= 0) return previous;

    const byWidth = width / STAGE_W;
    const byHeight = height !== null && Number.isFinite(height) && height > 0 ? height / STAGE_H : byWidth;

    return Math.max(0.15, Math.min(byWidth, byHeight));
}
