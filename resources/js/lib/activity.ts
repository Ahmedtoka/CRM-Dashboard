import { translate, type Locale } from '@/i18n';
import { formatMoney, formatSeconds } from '@/lib/format';
import type { ActivityLogItem } from '@/types/admin';
import type { PlatformOption } from '@/types/crm';

/**
 * Human sentence for an activity log row: one translation key per action (`activity.message.sent`)
 * filled with {actor, platform, …meta}. Unknown actions fall back to the raw action key.
 */
export function activitySentence(log: ActivityLogItem, locale: Locale, platforms: PlatformOption[]): string {
    const key = `activity.${log.action}`;
    const template = translate(locale, key);

    if (template === key) {
        return log.action;
    }

    const meta = log.meta ?? {};
    const text = (value: unknown) => (value === null || value === undefined ? '' : String(value));
    const actor = log.user?.name ?? translate(locale, `activity.actors.${log.actor_type ?? 'system'}`);
    const platform = platforms.find((p) => p.value === log.platform)?.label ?? log.platform ?? '';
    const reason = text(meta.reason);
    const reasonKey = `reports.reasons.${reason}`;
    const status = text(meta.status);

    return translate(locale, key, {
        actor,
        platform,
        duration: formatSeconds(Number(meta.seconds ?? 0), locale),
        reason: reason ? (translate(locale, reasonKey) === reasonKey ? reason : translate(locale, reasonKey)) : '',
        total: formatMoney(text(meta.total) || 0, locale),
        status: status ? translate(locale, `shipment.status.${status}`) : '',
        rule: text(meta.rule_name ?? meta.rule_id),
        other: text(meta.other_name ?? meta.other_id),
        version: text(meta.version),
    });
}

/** Order id when the log's subject is an order (for "open order" links). */
export function activityOrderId(log: ActivityLogItem): number | null {
    return log.subject_type?.endsWith('Order') && log.subject_id ? log.subject_id : null;
}
