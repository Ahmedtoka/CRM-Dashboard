import ar from '@/i18n/ar';
import { writeChangeKey } from '@/lib/ads';
import { describe, expect, it } from 'vitest';

describe('writeChangeKey', () => {
    it('labels a Stop, a Run and a platform archive', () => {
        expect(writeChangeKey('PAUSED')).toBe('ads.control.write.done_stop');
        expect(writeChangeKey('ACTIVE')).toBe('ads.control.write.done_run');
        expect(writeChangeKey('ARCHIVED')).toBe('ads.control.write.done_archive');
        expect((ar as any).ads.control.write.done_archive).toBe('اتأرشف على المنصة');
    });
});
