/**
 * Pure helpers for the "شكلها عند العميل" preview (design 2026-09-18 §5): what one step looks
 * like in Messenger, mirroring app/Bot/Flows/FlowPrompter.php (menu options hidden for an
 * inactive script or flow target, the main-menu chip under choices, the summary's confirm/edit
 * buttons) and Messenger's limits (13 quick replies, 20-character titles).
 */
import type { FlowDefinition, FlowListRow, FlowScriptOption, FlowStep } from '@/types/flows';
import { MAX_OPTION_TITLE, MAX_QUICK_REPLIES, fieldsOf, parseAction } from './flowGraph';

/** `{time_greeting}` in the preview (the live bot picks صباح/مساء by Cairo time). */
export const PREVIEW_GREETING = 'مساء الخير';
export const PREVIEW_CASE_ID = '1234';

export const MAIN_MENU_CHIP = 'القائمة الرئيسية';
export const SUMMARY_CHIPS = ['تمام، سجل', 'عايزة أعدل'];

/** FlowPrompter::LABELS with a sample answer each, in the order the owner reads them. */
const SUMMARY_SAMPLES: [field: string, label: string, sample: string | null][] = [
    ['order_number', 'رقم الأوردر', '10234'],
    ['order_ref_text', 'بيانات الأوردر', 'فستان أسود مقاس M'],
    ['reason', 'السبب', 'المقاس صغير'],
    ['request', 'الطلب', 'استبدال'],
    ['product_photo', 'صورة المنتج (✅)', null],
    ['defect_photo', 'صورة العيب (✅)', null],
    ['complaint_type', 'نوع الشكوى', 'تأخير في التوصيل'],
    ['branch_name', 'الفرع', 'فرع مدينة نصر'],
    ['visit_date', 'تاريخ الزيارة', 'امبارح'],
    ['name', 'الاسم', 'منى أحمد'],
    ['phone', 'الموبايل', '01012345678'],
    ['description', 'التفاصيل', 'القماش فيه عيب'],
    ['edit_details', 'التعديل المطلوب', 'تغيير العنوان'],
];

export type HiddenReason = 'script_inactive' | 'flow_inactive' | 'no_action';

export interface PreviewChip {
    /** what Messenger shows (cut at 20 characters) */
    title: string;
    full: string;
    truncated: boolean;
    /** why the engine will not show it, or null when it is shown */
    hidden: HiddenReason | null;
    /** past the 13-quick-reply limit */
    overLimit: boolean;
}

export interface StepPreviewModel {
    text: string;
    chips: PreviewChip[];
    /** the step sends a script that is missing or inactive, so nothing is sent */
    scriptMissing: boolean;
    /** what the customer is expected to send next (photo / order / phone) */
    expects: 'photo' | 'order' | 'phone' | null;
    /** a note about what the engine does instead of a message (status, handover, end, branches list) */
    note: 'status' | 'handover' | 'end' | 'branches_list' | null;
}

export function renderPlaceholders(text: string): string {
    return text.replaceAll('{time_greeting}', PREVIEW_GREETING).replaceAll('{case_id}', PREVIEW_CASE_ID);
}

export function truncateTitle(title: string): { title: string; truncated: boolean } {
    const chars = [...title];
    return chars.length > MAX_OPTION_TITLE ? { title: chars.slice(0, MAX_OPTION_TITLE - 1).join('') + '…', truncated: true } : { title, truncated: false };
}

/** An active script with a body (what FlowPrompter::script() needs), or null. */
export function activeScriptBody(scripts: FlowScriptOption[], key: string | undefined): string | null {
    if (!key) return null;
    const script = scripts.find((s) => s.key === key);
    const body = (script?.body ?? '').trim();
    return script && script.is_active !== false && body !== '' ? body : null;
}

/** Why FlowPrompter::visibleMenuOptions() drops a menu option, or null when it stays. */
export function hiddenReason(action: string | undefined, scripts: FlowScriptOption[], flows: FlowListRow[]): HiddenReason | null {
    if (!action) return 'no_action';
    const { kind, key } = parseAction(action);
    if (kind === 'script') return activeScriptBody(scripts, key) === null ? 'script_inactive' : null;
    if (kind === 'flow' || kind === 'menu') return key !== '' && flows.some((f) => f.key === key && f.is_active) ? null : 'flow_inactive';
    return null;
}

/** "• label: sample" lines for the fields this flow collects (FlowPrompter::summaryLines on sample data). */
export function sampleSummaryLines(def: FlowDefinition): string[] {
    const used = new Set(fieldsOf(def));
    let rows = SUMMARY_SAMPLES.filter(([field]) => used.has(field));
    if (!rows.length) rows = SUMMARY_SAMPLES.filter(([field]) => field === 'name' || field === 'phone');
    return rows.map(([, label, sample]) => (sample === null ? `• ${label}` : `• ${label}: ${sample}`));
}

function chip(title: string, hidden: HiddenReason | null = null): PreviewChip {
    const cut = truncateTitle(title);
    return { title: cut.title, full: title, truncated: cut.truncated, hidden, overLimit: false };
}

/** Marks visible chips past the 13th as over the limit (hidden ones do not use a slot). */
function applyLimit(chips: PreviewChip[]): PreviewChip[] {
    let shown = 0;
    return chips.map((c) => {
        if (c.hidden) return c;
        shown++;
        return shown > MAX_QUICK_REPLIES ? { ...c, overLimit: true } : c;
    });
}

export function previewStep(
    step: FlowStep,
    def: FlowDefinition,
    scripts: FlowScriptOption[],
    flows: FlowListRow[],
): StepPreviewModel {
    const model: StepPreviewModel = { text: renderPlaceholders(step.text ?? ''), chips: [], scriptMissing: false, expects: null, note: null };

    switch (step.type) {
        case 'menu':
            model.chips = applyLimit((step.options ?? []).map((o) => chip(o.title, hiddenReason(o.action, scripts, flows))));
            break;
        case 'choice': {
            const options = step.options ?? [];
            const chips = options.map((o) => chip(o.title));
            if (options.length < MAX_QUICK_REPLIES) chips.push(chip(MAIN_MENU_CHIP));
            model.chips = applyLimit(chips);
            break;
        }
        case 'summary':
            model.text = [model.text, ...sampleSummaryLines(def)].filter((line) => line !== '').join('\n');
            model.chips = SUMMARY_CHIPS.map((title) => chip(title));
            break;
        case 'script':
        case 'record_case': {
            const body = activeScriptBody(scripts, step.script);
            model.text = body === null ? '' : renderPlaceholders(body);
            model.scriptMissing = body === null && (step.type === 'script' || !!step.script);
            break;
        }
        case 'photo':
        case 'order':
        case 'phone':
            model.expects = step.type;
            break;
        case 'status':
        case 'handover':
        case 'end':
        case 'branches_list':
            model.note = step.type;
            break;
    }

    return model;
}
