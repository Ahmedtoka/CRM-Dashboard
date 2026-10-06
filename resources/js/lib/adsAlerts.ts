import { translate, type Locale, type TranslationParams } from '@/i18n';
import type { AlertParams, AlertSeverity } from '@/types/ads';

export const SEVERITY_ORDER: AlertSeverity[] = ['critical', 'high', 'medium', 'info'];
export const SNOOZE_OPTIONS = ['tomorrow', '3d', '7d'] as const;
export type SnoozeOption = (typeof SNOOZE_OPTIONS)[number];
/** Same list as AlertStore::DISMISS_REASONS (server validates). */
export const DISMISS_REASONS = ['learning', 'seasonal', 'tiny_budget', 'wrong_numbers', 'handling_it', 'testing', 'other'] as const;
export type DismissReason = (typeof DISMISS_REASONS)[number];

/** Params whose value is a word to translate, not a number or a name. */
const LOOKUPS: Record<string, string> = { result: 'ads.alerts.results.', status: 'ads.alerts.product_status.' };

function word(locale: Locale, prefix: string, value: string): string {
    const key = prefix + value;
    const text = translate(locale, key);

    return text === key ? value : text;
}

/**
 * The reason as one Egyptian Arabic (or English) sentence from the catalogue, plus the notes the engine flags:
 * default break-even (D12), «ضيف بديل الأول» (no healthy alternative) and «الإنبوكس كان بطيء» (slow inbox).
 */
export function alertSentence(r: { sentence_key: string; params: AlertParams | null | undefined }, locale: Locale): string {
    const raw = r.params ?? {};
    const params: TranslationParams = {};
    for (const [k, v] of Object.entries(raw)) {
        if (v === null || typeof v === 'boolean') continue;
        params[k] = LOOKUPS[k] ? word(locale, LOOKUPS[k], String(v)) : v;
    }
    let text = translate(locale, `ads.alerts.rules.${r.sentence_key}`, params);
    if (raw.floor_default === true) text += ' ' + translate(locale, 'ads.alerts.floor_default', { floor: Number(raw.floor ?? 2.5) });
    if (raw.no_alternative === true) text += ' ' + translate(locale, 'ads.alerts.no_alternative');
    if (raw.inbox_slow === true) text += ' ' + translate(locale, 'ads.alerts.inbox_slow');

    return text;
}

export function groupBySeverity<T extends { severity: AlertSeverity }>(cards: T[]): { severity: AlertSeverity; cards: T[] }[] {
    return SEVERITY_ORDER.map((severity) => ({ severity, cards: cards.filter((c) => c.severity === severity) })).filter((g) => g.cards.length > 0);
}
