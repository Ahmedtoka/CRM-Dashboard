import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/** Phase 1 of the redesign (DESIGN.md §2): Le Voile tokens feed the shadcn names, light and dark. */
const css = readFileSync(resolve(__dirname, '../../../css/app.css'), 'utf8');

function block(selector: string): string {
    const start = css.indexOf(`${selector} {`);
    expect(start, `${selector} block`).toBeGreaterThan(-1);

    return css.slice(start, css.indexOf('\n    }', start));
}

function raw(scope: string, name: string): string | undefined {
    return block(scope).match(new RegExp(`--${name}:\\s*([^;]+);`))?.[1].trim();
}

/** A token's value in a scope, following `var(--x)` (a name dark doesn't override keeps the light mapping). */
function value(scope: string, name: string): string | undefined {
    const v = raw(scope, name) ?? (scope === ':root' ? undefined : raw(':root', name));
    const ref = v?.match(/^var\(--([a-z0-9-]+)\)$/)?.[1];

    return ref ? value(scope, ref) : v;
}

const RAW = [
    'surface', 'surface-2', 'sunken', 'line', 'line-strong', 'fg', 'fg-2', 'fg-3', 'brand', 'brand-hover', 'on-brand', 'brand-soft', 'brand-ink',
    'rail', 'rail-2', 'rail-fg', 'rail-fg-2', 'rail-line', 'ok', 'ok-soft', 'warn', 'warn-soft', 'warn-solid', 'danger', 'danger-soft', 'danger-solid',
    'info', 'info-soft', 'note', 'note-soft', 'note-ink', 'chart-1', 'chart-soft', 'e1', 'e2', 'e3',
];

describe('design tokens', () => {
    it('maps the brand mauve onto --primary in light and dark', () => {
        expect(value(':root', 'primary')).toBe(value(':root', 'brand'));
        expect(value('.dark', 'primary')).toBe(value('.dark', 'brand'));
        expect(value(':root', 'brand')).toMatch(/^285\.7 43\.8% 30%/); // #5E2B6E
        expect(value('.dark', 'brand')).toMatch(/^283 47\.6% 75\.3%/); // #CDA2DE
    });

    it('defines every raw token in both themes', () => {
        for (const name of RAW) {
            expect(value(':root', name), `light --${name}`).toBeDefined();
            expect(value('.dark', name), `dark --${name}`).toBeDefined();
        }
    });

    it('paints the sidebar with the aubergine rail', () => {
        expect(value(':root', 'sidebar-background')).toBe(value(':root', 'rail'));
        expect(value('.dark', 'sidebar-background')).toBe(value('.dark', 'rail'));
    });

    it('keeps the old shadcn names pointing at the new palette', () => {
        expect(value(':root', 'background')).toMatch(/^280 14\.3% 95\.9%/); // #F5F3F6
        expect(value(':root', 'card')).toBe('0 0% 100%');
        expect(value(':root', 'ring')).toMatch(/^283 37% 46\.7%/); // #8A4BA3
        expect(value(':root', 'destructive')).toBe(value(':root', 'danger-solid'));
        expect(value(':root', 'success')).toBe(value(':root', 'ok'));
        expect(css).not.toContain('#1877F2');
    });
});
