/**
 * The geometry of the live board's room: one fixed stage that is scaled to the screen, as in the
 * approved simulator (docs/design/queue-routing-simulator.html). Everything here is in stage
 * pixels; nothing reads the DOM.
 *
 * The stage is wide and low (the numbers live in the HTML bar above it), so at 1440 × 900 with the
 * sidebar collapsed it is drawn at about its own size: the type floor of 12 stage px stays ≥ 11 px
 * on screen.
 */

export const STAGE_W = 1320;
export const STAGE_H = 728;

export interface Box {
    x: number;
    y: number;
    w: number;
    h: number;
}

/** The band along the top: the wall screen, the leader's desk, the reception. */
const TOP = { y: 12, h: 200 };
const GUTTER = 12;
const EDGE = 16;
/** Where the carpet and the lounge start, under the top band. */
const FLOOR = { y: 220, h: 496 };

export const WALL: Box = { x: EDGE, y: TOP.y, w: 432, h: TOP.h };

/** The leader's desk cell: her desk on the start side, her row of windows beside it. */
export const LEADER = { w: 484, h: TOP.h };
/** Where her row of windows starts inside her cell. */
export const LEADER_SLOTS = { x: 264, y: 64 };

/** A desk cell at full size (the row of windows, her desk, the moderator, her name and status). */
export const CELL = { w: 220, h: 240, gapX: 10, gapY: 0 };
export const SLOT = { w: 64, h: 70, gap: 8 };

export const MAX_DESKS = 12;
/** The most customers the lounge can seat (cards, then heads); the rest are counted. */
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

export interface RoomGeometry {
    carpet: Box;
    lounge: Box;
    reception: Box;
    /** The leader's cell, centred in the band between the wall and the reception. */
    leader: DeskBox;
}

/** The lounge's width for a number of waiting customers. */
export function loungeWidth(waiting: number): number {
    return waiting <= 0 ? 200 : waiting <= 4 ? 260 : 348;
}

/** The lounge narrows when few customers wait; the carpet takes the width back (spec §2.2). */
export function roomGeometry(waiting: number): RoomGeometry {
    const loungeW = loungeWidth(waiting);
    const carpetW = STAGE_W - EDGE * 2 - GUTTER - loungeW;
    const carpet: Box = { x: EDGE, y: FLOOR.y, w: carpetW, h: FLOOR.h };
    const lounge: Box = { x: EDGE + carpetW + GUTTER, y: FLOOR.y, w: loungeW, h: FLOOR.h };
    const reception: Box = { x: lounge.x, y: TOP.y, w: loungeW, h: TOP.h };

    const bandX = WALL.x + WALL.w + GUTTER;
    const bandW = reception.x - GUTTER - bandX;

    return { carpet, lounge, reception, leader: { x: bandX + Math.max(0, (bandW - LEADER.w) / 2), y: TOP.y, scale: 1 } };
}

/**
 * Any number of desks from 1 to 12 on the carpet: the grid (columns × rows) that keeps the
 * desks largest, centred, never larger than life. Beyond what fits, the cells shrink together.
 */
export function deskLayout(count: number, carpet: Box): DeskBox[] {
    const n = Math.max(0, Math.min(MAX_DESKS, Math.floor(count)));
    if (n === 0) return [];

    const pad = 8;
    const availW = carpet.w - pad * 2;
    const availH = carpet.h - pad * 2;
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
    const top = carpet.y + pad + Math.max(0, (availH - gridH) / 2);

    return Array.from({ length: n }, (_, i) => {
        const row = Math.floor(i / cols);
        const inRow = row === rows - 1 ? n - cols * (rows - 1) : cols;
        const rowW = (inRow * stepX - CELL.gapX) * scale;
        const left = carpet.x + pad + Math.max(0, (availW - rowW) / 2);

        return { x: left + (i % cols) * stepX * scale, y: top + row * stepY * scale, scale };
    });
}

/** The row of windows shrinks as one when a moderator has more windows than fit her desk. */
export function slotScale(count: number, rowWidth: number = CELL.w): number {
    const n = Math.max(1, count);

    return Math.min(1, (rowWidth - 12) / (n * SLOT.w + (n - 1) * SLOT.gap));
}

/** Centre of window `index` (0-based) of a desk with `count` windows, in stage pixels. */
export function slotCentre(box: DeskBox, index: number, count: number, leader = false): Point {
    const left = leader ? LEADER_SLOTS.x : 0;
    const top = leader ? LEADER_SLOTS.y : 0;
    const s = slotScale(count);
    const offset = (index - (count - 1) / 2) * (SLOT.w + SLOT.gap) * s;

    return { x: box.x + (left + CELL.w / 2 + offset) * box.scale, y: box.y + (top + (SLOT.h * s) / 2) * box.scale };
}

/** How the lounge seats its customers at its width: cards in one or two columns, then heads. */
export interface LoungeSeats {
    cols: number;
    cardW: number;
    cards: number;
    headsPerRow: number;
    heads: number;
}

const CARD_TOP = 56;
const CARD_PITCH = 68;
const HEAD = { w: 24, h: 34, rows: 2 };

export function loungeSeats(lounge: Box): LoungeSeats {
    const cols = lounge.w < 300 ? 1 : 2;
    const cardW = Math.floor((lounge.w - 20 - (cols - 1) * 8) / cols);
    const headsTop = lounge.h - 22 - HEAD.rows * HEAD.h;
    const rows = Math.max(0, Math.floor((headsTop - CARD_TOP) / CARD_PITCH));
    const headsPerRow = Math.max(1, Math.floor((lounge.w - 20) / HEAD.w));

    return { cols, cardW, cards: Math.min(CARDS, rows * cols), headsPerRow, heads: Math.min(HEADS, headsPerRow * HEAD.rows) };
}

/** Top-left of lounge card `index`, relative to the lounge. */
export function cardSpot(index: number, lounge: Box): Point {
    const { cols, cardW } = loungeSeats(lounge);

    return { x: 10 + (index % cols) * (cardW + 8), y: CARD_TOP + Math.floor(index / cols) * CARD_PITCH };
}

/** Top of the heads' rows, relative to the lounge. */
export function headsTop(lounge: Box): number {
    return lounge.h - 22 - HEAD.rows * HEAD.h;
}

/** Centre of lounge seat `index` (a card, then a head), in stage pixels. */
export function seatCentre(index: number, lounge: Box): Point {
    if (index < 0) return { x: lounge.x + lounge.w / 2, y: lounge.y + lounge.h - 40 };

    const seats = loungeSeats(lounge);
    if (index < seats.cards) {
        const c = cardSpot(index, lounge);

        // The seated figure sits at the card's end.
        return { x: lounge.x + c.x + seats.cardW - 22, y: lounge.y + c.y + 32 };
    }

    const j = Math.min(index - seats.cards, seats.heads - 1);

    return {
        x: lounge.x + 10 + (j % seats.headsPerRow) * HEAD.w + HEAD.w / 2,
        y: lounge.y + headsTop(lounge) + Math.floor(j / seats.headsPerRow) * HEAD.h + 14,
    };
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
