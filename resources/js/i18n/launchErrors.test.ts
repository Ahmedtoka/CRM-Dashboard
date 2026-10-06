import { translate } from '@/i18n';
import ar from '@/i18n/ar';
import en from '@/i18n/en';
import { launchErrorText } from '@/lib/launch';
import { describe, expect, it } from 'vitest';

/**
 * Every machine code the backend can put on a launch screen has words in both languages:
 * - reasons: LaunchService::REASONS + SYSTEM_REASONS (decision_code and audit meta code) -> ads.launch.reason.<code>
 * - last_error codes: every WriteDenied::make('...'), every literal code the write executor finishes or fails a step with,
 *   every WriteRefused('...') reason and every literal 'last_error' => '...' in app/Ads -> ads.launch.error.<code>
 * The codes are read from the PHP sources, so a new code without a translation fails here.
 */
const php = import.meta.glob('/app/Ads/**/*.php', { query: '?raw', import: 'default', eager: true }) as Record<string, string>;
const all = Object.values(php).join('\n');
const service = Object.entries(php).find(([p]) => p.endsWith('/Launch/LaunchService.php'))![1];

const list = (name: string): string[] => {
    const m = service.match(new RegExp(`const ${name} = \\[([^\\]]*)\\]`));
    return [...(m?.[1] ?? '').matchAll(/'([a-z_]+)'/g)].map((x) => x[1]);
};
const grab = (re: RegExp): string[] => [...all.matchAll(re)].map((x) => x[1]);

const reasons = [...new Set([...list('REASONS'), ...list('SYSTEM_REASONS')])];
/** Codes not written as a literal next to the call: WriteSwitch::CODE, the approve settle fallback, the 'unknown' fallback. */
const HARD = ['writes_disabled', 'failed', 'other'];
const errors = [
    ...new Set([
        ...grab(/WriteDenied::make\('([a-z_]+)'/g),
        ...grab(/finish\(\$x, AdWriteAction::[A-Z_]+, '([a-z_]+)'/g),
        ...grab(/failStep\(\$x, \$step, '([a-z_]+)'/g),
        ...grab(/new WriteRefused\('([a-z_]+)'/g),
        ...grab(/'last_error' => '([a-z_]+)'/g),
        ...grab(/\?: '([a-z_]+)'\)/g).filter((c) => c === 'resolved_by_hand'),
        ...HARD,
    ]),
];

function has(dict: unknown, key: string): boolean {
    let node: unknown = dict;
    for (const part of key.split('.')) {
        if (node === null || typeof node !== 'object' || !(part in (node as Record<string, unknown>))) return false;
        node = (node as Record<string, unknown>)[part];
    }
    return typeof node === 'string';
}

describe('launch codes have words', () => {
    it('reads the codes from the PHP sources', () => {
        expect(reasons).toContain('stuck_launching');
        expect(reasons).toContain('product_gone');
        expect(errors).toContain('approve_incomplete');
        expect(errors).toContain('cap_exceeded');
        expect(errors.length).toBeGreaterThan(30);
    });

    it.each(reasons)('reason %s in ar and en', (code) => {
        expect(has(ar, `ads.launch.reason.${code}`)).toBe(true);
        expect(has(en, `ads.launch.reason.${code}`)).toBe(true);
    });

    it.each(errors)('error %s in ar and en', (code) => {
        expect(has(ar, `ads.launch.error.${code}`)).toBe(true);
        expect(has(en, `ads.launch.error.${code}`)).toBe(true);
    });
});

describe('launchErrorText', () => {
    const t = (key: string) => translate('en', key);

    it('translates comma-separated codes and never shows a raw key', () => {
        expect(launchErrorText('cap_exceeded,approval_required', t)).toBe('No runs left today · This ad was never approved');
        expect(launchErrorText('some_new_code', t)).toBe('The run was refused');
    });

    it('keeps a platform sentence as it is', () => {
        expect(launchErrorText('Invalid parameter (#100)', t)).toBe('Invalid parameter (#100)');
        expect(launchErrorText(null, t)).toBe('');
    });
});
