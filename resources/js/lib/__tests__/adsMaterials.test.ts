import { translate } from '@/i18n';
import { MATERIAL_STATUSES, statusCounts } from '@/lib/adsMaterials';
import type { MaterialStats } from '@/types/ads';
import { describe, expect, it } from 'vitest';

const stats: MaterialStats = {
    total: 20,
    activated: 6,
    not_started: 7,
    done: 4,
    in_review: 3,
    paused: 3,
    reels: 0,
    posts: 0,
    carousels: 0,
    in_stock: 0,
    out_of_stock: 0,
    need_stop: 0,
};

describe('statusCounts (final fix 2)', () => {
    it('gives the library header one count per derived status, in the filter order', () => {
        expect(statusCounts(stats)).toEqual([
            { status: 'new', value: 4 },
            { status: 'in_review', value: 3 },
            { status: 'live', value: 6 },
            { status: 'paused', value: 3 },
            { status: 'retired', value: 4 },
        ]);
    });

    it('uses the filter names, never the old «لسه ما بدأتش»', () => {
        const labels = MATERIAL_STATUSES.map((s) => translate('ar', `ads.materials.status.${s}`));
        expect(labels).toEqual(['جديدة', 'في المراجعة', 'شغالة', 'واقفة', 'خلصت']);
        expect(labels).not.toContain('لسه ما بدأتش');
    });
});
