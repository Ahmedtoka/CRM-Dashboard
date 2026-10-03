/**
 * Thread media sizing (spec §1.2). Pure: the box is sized from the stored attachment
 * width/height before the file loads, so nothing in the thread jumps.
 */

export interface BoxSize {
    width: number;
    height: number;
}

export const MEDIA_MAX_W = 280;
export const MEDIA_MAX_H = 360;

/** Fit `w × h` inside `maxW × maxH`, never upscaling; unknown sizes get a 4:3 box. */
export function fitBox(w: number | null, h: number | null, maxW = MEDIA_MAX_W, maxH = MEDIA_MAX_H): BoxSize {
    if (!w || !h) return { width: maxW, height: Math.round(maxW * 0.75) };
    const s = Math.min(1, maxW / w, maxH / h);
    return { width: Math.round(w * s), height: Math.round(h * s) };
}

/** The outer box of a multi-image grid: 2 → 280×160, 3 → 280×240 (1 big + 2 small), 4+ → 280×280. */
export function gridBox(count: number): BoxSize {
    if (count <= 2) return { width: MEDIA_MAX_W, height: 160 };
    if (count === 3) return { width: MEDIA_MAX_W, height: 240 };
    return { width: MEDIA_MAX_W, height: MEDIA_MAX_W };
}

/**
 * Inline style for a reserved media box. The width is a cap, not a fixed size: on a
 * narrow phone the bubble may be thinner than 280 px, so the box shrinks with
 * `max-width: 100%` and keeps its height through `aspect-ratio`.
 */
export function boxStyle(size: BoxSize): Record<string, string> {
    return { width: `${size.width}px`, maxWidth: '100%', aspectRatio: `${size.width} / ${size.height}` };
}
