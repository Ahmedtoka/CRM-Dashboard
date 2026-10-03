import { formatNumber, translate, type Locale } from '@/i18n';
import { formatMoney, formatSeconds } from '@/lib/format';
import type { ActivityLogItem } from '@/types/admin';
import type { PlatformOption } from '@/types/crm';

/**
 * Human sentence for an activity log row: one translation key per action (`activity.message.sent`)
 * filled with {actor, platform, …meta}. An unknown action never shows its raw key: a queue action
 * reads «نشاط في الطابور», anything else the generic label of its group, else «نشاط».
 */
export function activitySentence(log: ActivityLogItem, locale: Locale, platforms: PlatformOption[]): string {
    const key = `activity.${log.action}`;
    const template = translate(locale, key);

    if (template === key) {
        return unknownActionLabel(log.action, locale);
    }

    const meta = log.meta ?? {};
    const text = (value: unknown) => (value === null || value === undefined ? '' : String(value));
    const actor = log.user?.name ?? translate(locale, `activity.actors.${log.actor_type ?? 'system'}`);
    const platform = platforms.find((p) => p.value === log.platform)?.label ?? log.platform ?? '';
    const reason = text(meta.reason);
    const reasonKey = `reports.reasons.${reason}`;
    const status = text(meta.status);

    return translate(locale, key, {
        actor: isolate(actor),
        platform: isolate(platform),
        duration: formatSeconds(Number(meta.seconds ?? 0), locale),
        reason: reason ? (translate(locale, reasonKey) === reasonKey ? reason : translate(locale, reasonKey)) : '',
        total: formatMoney(text(meta.total) || 0, locale),
        status: status ? translate(locale, `shipment.status.${status}`) : '',
        rule: isolate(text(meta.rule_name ?? meta.rule_id)),
        other: isolate(text(meta.other_name ?? meta.other_id)),
        version: text(meta.version),
        ticket: ticketNumber(meta.ticket, locale),
    });
}

/**
 * Free text (a name, a rule, «Messenger») inside the sentence keeps its own direction without
 * turning the sentence around: first-strong isolate (U+2068 … U+2069), the text form of dir="auto".
 */
function isolate(value: string): string {
    return value ? `\u2068${value}\u2069` : '';
}

/** A ticket number in the page's digits, without grouping (تذكرة ١٢٣٤ not ١٬٢٣٤). */
function ticketNumber(value: unknown, locale: Locale): string {
    const n = Number(value);

    return value === null || value === undefined || value === '' || !Number.isFinite(n) ? '' : formatNumber(locale, n, { useGrouping: false });
}

function unknownActionLabel(action: string, locale: Locale): string {
    const group = action.split('.')[0];
    const key = `activity.${group}.other`;
    const label = translate(locale, key);

    return label === key ? translate(locale, 'activity.ui.unknown') : label;
}

/** Short label for the action filter: the filter key, else the same no-raw-key fallback as the sentence. */
export function activityActionLabel(action: string, locale: Locale): string {
    const key = `activity_filter.${action}`;
    const label = translate(locale, key);

    return label === key ? unknownActionLabel(action, locale) : label;
}

/** Order id when the log's subject is an order (for "open order" links). */
export function activityOrderId(log: ActivityLogItem): number | null {
    return log.subject_type?.endsWith('Order') && log.subject_id ? log.subject_id : null;
}
