/**
 * Pure helpers for the "شكلها عند العميل" preview (design 2026-09-18 §5): what one step looks
 * like in Messenger, mirroring app/Bot/Flows/FlowPrompter.php (menu options hidden for an
 * inactive script or flow target, the main-menu chip under choices, the summary's confirm/edit
 * buttons) and Messenger's limits (13 quick replies, 20-character titles).
 */
import type { OutboundCards } from '@/types/crm';
import type { FlowDefinition, FlowListRow, FlowScriptOption, FlowStep } from '@/types/flows';
import { DEFAULT_STEP_TEXT, MAX_OPTION_TITLE, MAX_QUICK_REPLIES, fieldsOf, parseAction } from './flowGraph';

/** `{time_greeting}` in the preview (the live bot picks صباح/مساء by Cairo time). */
export const PREVIEW_GREETING = 'مساء الخير';
export const PREVIEW_CASE_ID = '1234';
/** Sample values for the flow placeholders (FlowPrompter::renderText, 2026-09-19). */
export const PREVIEW_FIRST_NAME = 'سارة';
export const PREVIEW_ORDER_NUMBER = '1047';
export const PREVIEW_EXCHANGE_PRODUCT = 'عباية كتان';
/** Sample status-card values (app/Bot/Flows/Steps/StatusStep.php, an order on its way). */
export const PREVIEW_ORDER_CARD: Record<string, string> = {
    order_date: 'الخميس 17/9',
    order_items: '3 قطع',
    order_status: 'اتشحن ومع شركة الشحن',
    order_eta: 'الإثنين 21/9 لحد الأربعاء 23/9',
    order_tracking: 'https://track.example/1047',
};

export const MAIN_MENU_CHIP = 'القائمة الرئيسية';
export const SUMMARY_CHIPS = ['تمام، سجل', 'عايزة أعدل'];

/** What the `order_items` step sends for a sample order (mirrors app/Bot/Flows/Steps/OrderItemsStep.php). */
export const ORDER_ITEMS_DEFAULT_TEXT = 'اختاري القطعة اللي عايزة ترجعيها أو تبدليها 👇';
export const ORDER_ITEMS_SAMPLE_HEADER = 'لقيت أوردر #1047 باسم سارة أحمد — اتسلم يوم 12 سبتمبر';
export const ORDER_ITEMS_SAMPLE: { title: string; line: string }[] = [
    { title: 'فستان ليلى', line: 'فستان ليلى — أسود / M × 1 — 850 ج.م' },
    { title: 'طرحة شيفون', line: 'طرحة شيفون × 2 — 150 ج.م' },
    { title: 'عباية كتان', line: 'عباية كتان × 1 — 1,200 ج.م' },
];
export const ORDER_ITEMS_MULTI_CHIP = 'كذا قطعة';

/** The `contact` step with a known name and mobile (app/Bot/Flows/Steps/ContactStep.php CONFIRM_TEXT). */
export const CONTACT_CONFIRM_SAMPLE = 'هنتواصل مع حضرتك باسم «سارة» على رقم 0106•••6611 — تمام كده؟';
export const CONTACT_CHIPS = ['أيوه تمام', 'رقم تاني'];
export const CONTACT_DEFAULT_TEXT = 'عشان الفريق يقدر يتواصل مع حضرتك 🌸 ابعتيلي اسمك ورقم موبايلك في رسالة واحدة (مثلاً: سارة 01012345678)';

/** The `item_changes` step for a sample piece (app/Bot/Flows/Steps/ItemChangesStep.php). */
export const ITEM_CHANGES_SAMPLE = '«فستان ليلى (أسود / M)»\nتحبي تبدليها ولا تشيليها من الأوردر؟';
export const ITEM_CHANGES_CHIPS = ['أبدلها', 'أشيلها'];

/** Sample branch cards (BranchFinder::cards): what the branches step sends after the area. */
export const SAMPLE_BRANCH_CARDS: OutboundCards = {
    type: 'generic',
    cards: [
        {
            title: 'El Marghany',
            subtitle: '126 El-Marghany St., Next to Shawermer\n📞 01094538159',
            buttons: [
                { type: 'web_url', title: '📍 الخريطة', url: 'https://goo.gl/maps/EbSV5rzAqCvvyAD37' },
                { type: 'phone', title: '📞 اتصل بالفرع', phone: '+201094538159' },
            ],
        },
        {
            title: 'El Hegaz',
            subtitle: '7 Ali Abd El-Razek St.\n📞 01063498056',
            buttons: [
                { type: 'web_url', title: '📍 الخريطة', url: 'https://goo.gl/maps/RFo6gESFKDgjSN8x5' },
                { type: 'phone', title: '📞 اتصل بالفرع', phone: '+201063498056' },
            ],
        },
    ],
};
export const SAMPLE_AREA_CHIPS = ['مصر الجديدة', 'مدينة نصر', 'المعادي'];

/** FlowPrompter::LABELS with a sample answer each, in the order the owner reads them. */
const SUMMARY_SAMPLES: [field: string, label: string, sample: string | null][] = [
    ['order_number', 'رقم الأوردر', '10234'],
    ['order_ref_text', 'بيانات الأوردر', 'فستان أسود مقاس M'],
    ['selected_items', 'القطع', 'فستان ليلى (أسود / M) × 1، طرحة شيفون × 1'],
    ['reason', 'السبب', 'المقاس صغير'],
    ['request', 'الطلب', 'استبدال'],
    ['request_kind', 'نوع الطلب', 'استبدال'],
    ['exchange_product', 'المنتج البديل', 'عباية كتان'],
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
    expects: 'photo' | 'order' | 'order_verify' | 'order_items' | 'product_link' | 'phone' | 'contact' | 'item_changes' | null;
    /** a second bot message the step may send instead (the contact step's one-message question) */
    alternative?: string;
    /** sample rich cards the step sends (the branch cards) */
    cards?: OutboundCards;
    /** a note about what the engine does instead of a message (status, handover, end, branches list) */
    note: 'status' | 'handover' | 'end' | 'branches_list' | null;
}

export function renderPlaceholders(text: string): string {
    return text
        .replaceAll('{time_greeting}', PREVIEW_GREETING)
        .replaceAll('{case_id}', PREVIEW_CASE_ID)
        .replaceAll('{customer_first_name}', PREVIEW_FIRST_NAME)
        .replaceAll('{order_number}', PREVIEW_ORDER_NUMBER)
        .replaceAll('{exchange_product_title}', PREVIEW_EXCHANGE_PRODUCT)
        .replace(/\{(order_date|order_items|order_status|order_eta|order_tracking)\}/g, (_, key: string) => PREVIEW_ORDER_CARD[key]);
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
    // order_items has no `field`: it always saves `selected_items`.
    if (Object.values(def.steps).some((s) => s.type === 'order_items')) used.add('selected_items');
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
            // A record_case/script step's own text is sent instead of its script (RecordCaseStep, FlowEngine).
            if (step.text?.trim()) {
                model.text = renderPlaceholders(step.text);
                break;
            }
            const body = activeScriptBody(scripts, step.script);
            model.text = body === null ? '' : renderPlaceholders(body);
            model.scriptMissing = body === null && (step.type === 'script' || !!step.script);
            break;
        }
        case 'order_items':
            model.text = [
                ORDER_ITEMS_SAMPLE_HEADER,
                ...ORDER_ITEMS_SAMPLE.map((item, i) => `${i + 1}. ${item.line}`),
                '',
                renderPlaceholders(step.text?.trim() || ORDER_ITEMS_DEFAULT_TEXT),
            ].join('\n');
            model.chips = applyLimit([...ORDER_ITEMS_SAMPLE.map((item) => chip(item.title)), chip(ORDER_ITEMS_MULTI_CHIP)]);
            model.expects = 'order_items';
            break;
        case 'order':
            model.expects = step.verify_owner ? 'order_verify' : 'order';
            break;
        case 'product_link':
            model.expects = 'product_link';
            break;
        case 'photo':
        case 'phone':
            model.expects = step.type;
            break;
        case 'status':
            // The card with its buttons for an order on its way (options shown only once delivered are left out).
            if (step.options?.length) {
                model.text = renderPlaceholders(step.text?.trim() || DEFAULT_STEP_TEXT.status);
                model.chips = applyLimit(step.options.filter((o) => o.when !== 'finished').map((o) => chip(o.title)));
                break;
            }
            model.note = 'status';
            break;
        case 'contact':
            // Known name + mobile: confirm them; otherwise the step's own one-message question.
            model.text = CONTACT_CONFIRM_SAMPLE;
            model.chips = CONTACT_CHIPS.map((title) => chip(title));
            model.alternative = renderPlaceholders(step.text?.trim() || CONTACT_DEFAULT_TEXT);
            model.expects = 'contact';
            break;
        case 'item_changes':
            model.text = ITEM_CHANGES_SAMPLE;
            model.chips = ITEM_CHANGES_CHIPS.map((title) => chip(title));
            model.expects = 'item_changes';
            break;
        case 'branches_list':
            model.chips = applyLimit([...SAMPLE_AREA_CHIPS.map((title) => chip(title)), chip(MAIN_MENU_CHIP)]);
            model.cards = SAMPLE_BRANCH_CARDS;
            model.note = 'branches_list';
            break;
        case 'handover':
        case 'end':
            model.note = step.type;
            break;
    }

    return model;
}
