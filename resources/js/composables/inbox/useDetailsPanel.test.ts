import { useDetailsPanel } from '@/composables/inbox/useDetailsPanel';
import { describe, expect, it } from 'vitest';
import { ref } from 'vue';

describe('useDetailsPanel', () => {
    it('opens as an overlay when a window is delivered and the column is closed', () => {
        const p = useDetailsPanel({ isXl: ref(true), initialOpen: false });
        p.onWindowDelivered();
        expect(p.overlay.value).toBe(true);
        expect(p.open.value).toBe(false); // her remembered choice is untouched
        expect(p.active.value).toBe(true);
    });

    it('does nothing when the column is already open, or below xl', () => {
        const wide = useDetailsPanel({ isXl: ref(true), initialOpen: true });
        wide.onWindowDelivered();
        expect(wide.overlay.value).toBe(false);

        const phone = useDetailsPanel({ isXl: ref(false), initialOpen: false });
        phone.onWindowDelivered();
        expect(phone.overlay.value).toBe(false);
        expect(phone.sheet.value).toBe(false);
    });

    it('closes the overlay with the toggle first, then follows her toggle', () => {
        const p = useDetailsPanel({ isXl: ref(true), initialOpen: false });
        p.onWindowDelivered();
        p.toggle();
        expect(p.overlay.value).toBe(false);
        expect(p.open.value).toBe(false);
        p.toggle();
        expect(p.open.value).toBe(true);
        expect(p.showColumn.value).toBe(true);
    });

    it('toggles the sheet below xl', () => {
        const p = useDetailsPanel({ isXl: ref(false), initialOpen: true });
        p.toggle();
        expect(p.sheet.value).toBe(true);
        expect(p.active.value).toBe(true);
        expect(p.showColumn.value).toBe(false);
    });

    it('drops the overlay and the sheet when she opens another chat', () => {
        const p = useDetailsPanel({ isXl: ref(true), initialOpen: false });
        p.onWindowDelivered();
        p.onSelect();
        expect(p.overlay.value).toBe(false);
    });
});
