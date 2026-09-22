<?php

namespace App\Bot\Flows;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's flows 3, 4 and 5 (2026-09-19, docs/superpowers/specs/2026-09-19-cancel-edit-and-complaint-flows.md),
 * published as new versions of `cancel_edit`, `complaint` and `branches` the way ReturnFlowUpgrade does it
 * (the live version and any draft archived and restorable; nothing happens once a flow already is this one).
 *
 * cancel_edit — order (ownership proof) → eligibility (still at the company = `order_editable`):
 *   shipped «للأسف الأوردر #… اتشحن خلاص…» / cancelled «الأوردر #… ملغي أصلاً 🌸» [كلم موظف] [تمام];
 *   «أهلاً يا … أوردر #… — تحبي تلغيه ولا تعدلي فيه؟» [إلغاء] [تعديل]
 *     إلغاء → the reason in her words (required) → case `cancel_edit` (request=cancel)
 *     تعديل → «عايزة تعدلي إيه؟» [القطع في الأوردر] [العنوان] [رقم الموبايل]
 *        القطع → pick (no return rules) → per piece [أبدلها] (a store link or a new size/colour) / [أشيلها]
 *        العنوان → the new address; رقم الموبايل → the new mobile → case `cancel_edit` (request=edit)
 *   No summary step; the closing texts carry the order number (never «#0»).
 *
 * complaint — [فرع] typed branch name or area → branch, visit date (buttons or her words); [شحن وتوصيل]
 *   [منتج] → the order (ownership proof); then `contact` (skipped with a verified order), the story with
 *   optional photos, case `complaint`. No summary step.
 *
 * branches — area buttons or a typed area/branch → branch cards → «تحبي حاجة تانية؟» [فرع في منطقة تانية].
 */
final class OwnerFlowsUpgrade
{
    public const NOTES = [
        'cancel_edit' => 'فلو الإلغاء والتعديل الجديد: قبل الشحن بس، سبب الإلغاء، وتعديل القطع أو العنوان أو الموبايل',
        'complaint' => 'فلو الشكوى الجديد: اسم الفرع أو المنطقة، الأوردر للشحن والمنتج، بيانات التواصل، وصور مع التفاصيل',
        'branches' => 'فلو الفروع الجديد: كروت الفروع بالخريطة والاتصال، وفرع في منطقة تانية',
    ];

    public const ASK_ORDER_TEXT = 'ممكن رقم الأوردر أو رقم الموبايل اللي اتعمل بيه الأوردر؟ 🌸';

    public const SHIPPED_TEXT = 'للأسف الأوردر #{order_number} اتشحن خلاص فمينفعش نلغيه أو نعدل فيه 🙏';

    public const ALREADY_CANCELLED_TEXT = 'الأوردر #{order_number} ملغي أصلاً 🌸';

    public const GREETING_TEXT = 'أهلاً يا {customer_first_name} 🌸 أوردر #{order_number} — تحبي تلغيه ولا تعدلي فيه؟';

    public const CANCEL_REASON_TEXT = 'ممكن تكتبيلي سبب الإلغاء؟ 🙏';

    public const CANCEL_DONE_TEXT = 'تمام ✅ سجلت طلب إلغاء أوردر #{order_number}، والفريق هيأكد معاكي الإلغاء في أقرب وقت 🌸';

    public const EDIT_WHAT_TEXT = 'عايزة تعدلي إيه؟';

    public const EDIT_ITEMS_TEXT = 'اختاري القطعة اللي عايزة تعدلي فيها 👇';

    public const NEW_ADDRESS_TEXT = 'اكتبي العنوان الجديد بالتفصيل (المحافظة - المنطقة - الشارع)';

    public const NEW_PHONE_TEXT = 'اكتبي رقم الموبايل الجديد 📞';

    public const EDIT_DONE_TEXT = 'تمام ✅ سجلت طلب تعديل أوردر #{order_number}، والفريق هيأكد معاكي التعديل 🌸';

    public const COMPLAINT_ASK_TEXT = 'آسفين جدًا لده 🙏 الشكوى بخصوص إيه؟';

    public const COMPLAINT_BRANCH_TEXT = 'اكتبي اسم الفرع، أو اختاري المنطقة من هنا 👇';

    public const VISIT_DATE_TEXT = 'كانت الزيارة إمتى تقريبًا؟';

    public const COMPLAINT_ITEMS_TEXT = 'الشكوى بخصوص أنهي قطعة؟ 👇 ولو على الأوردر كله اضغطي الزرار الأخير';

    public const COMPLAINT_PICK_BUTTON = 'الشكوى عن دي';

    public const COMPLAINT_SKIP_BUTTON = 'كل الأوردر';

    public const NOTE_2026_09_22 = 'فلو الشكوى: قطع الأوردر بالصور بعد التأكد من الأوردر';

    public const DESCRIPTION_TEXT = 'احكيلي حصل إيه بالتفصيل عشان نقدر نساعد حضرتك 🙏 (ولو فيه صورة ابعتيها)';

    public const COMPLAINT_DONE_TEXT = 'تمام ✅ سجلت الشكوى رقم #{case_id}، والفريق هيتواصل معاكي في أقرب وقت 🌸';

    public const BRANCHES_ASK_TEXT = 'حضرتك في أنهي منطقة؟ اختاري أو اكتبي اسم المنطقة أو الفرع 👇';

    public const BRANCHES_MORE_TEXT = 'تحبي حاجة تانية؟';

    /** The scripts these flows (and flow 7) need; inserted when missing, never overwritten. */
    public const SCRIPT_KEYS = ['handover_ask_topic', 'handover_in_hours', 'handover_after_hours', 'handover_no_hours'];

    /** @return array<string, array> the three definitions, keyed by flow */
    public static function definitions(): array
    {
        return [
            'cancel_edit' => self::cancelEditDefinition(),
            'complaint' => self::complaintDefinition(),
            'branches' => self::branchesDefinition(),
        ];
    }

    public static function cancelEditDefinition(): array
    {
        $agent = ['value' => 'agent', 'title' => 'كلم موظف', 'synonyms' => ['موظف', 'حد يكلمني', 'خدمة العملاء', 'حوليني'], 'action' => 'handover'];
        $ok = ['value' => 'ok', 'title' => 'تمام', 'synonyms' => ['تمام', 'اوك', 'ok', 'شكرا', 'ماشي'], 'next' => 'thanks'];

        return ['start' => 'order', 'steps' => [
            'order' => ['type' => 'order', 'field' => 'order', 'text' => self::ASK_ORDER_TEXT, 'verify_owner' => true, 'branches' => [
                ['field' => 'order_editable', 'in' => ['yes'], 'next' => 'request'],
                ['field' => 'order_editable', 'in' => ['no'], 'next' => 'shipped'],
                ['field' => 'order_editable', 'in' => ['cancelled'], 'next' => 'already_cancelled'],
            ], 'next' => 'not_found'],
            'shipped' => ['type' => 'choice', 'field' => 'shipped_choice', 'text' => self::SHIPPED_TEXT, 'options' => [$agent, $ok]],
            'already_cancelled' => ['type' => 'choice', 'field' => 'shipped_choice', 'text' => self::ALREADY_CANCELLED_TEXT, 'options' => [$agent, $ok]],
            'thanks' => ['type' => 'script', 'text' => TrackingFlowUpgrade::THANKS_TEXT, 'next' => 'end'],
            'not_found' => ['type' => 'choice', 'field' => 'not_found_choice', 'text' => TrackingFlowUpgrade::NOT_FOUND_TEXT, 'options' => [
                ['value' => 'agent', 'title' => 'كلم موظف', 'synonyms' => ['موظف', 'ايوه', 'اه', 'حوليني'], 'next' => 'agent'],
            ]],
            'agent' => ['type' => 'handover'],

            'request' => ['type' => 'choice', 'field' => 'request', 'text' => self::GREETING_TEXT, 'options' => [
                ['value' => 'cancel', 'title' => 'إلغاء', 'synonyms' => ['الغاء', 'الغي', 'الغيه', 'cancel'], 'next' => 'cancel_reason'],
                ['value' => 'edit', 'title' => 'تعديل', 'synonyms' => ['تعديل', 'اعدل', 'اغير', 'تغيير', 'edit'], 'next' => 'edit_what'],
            ]],
            'cancel_reason' => ['type' => 'text', 'field' => 'cancel_reason', 'text' => self::CANCEL_REASON_TEXT, 'next' => 'record_cancel'],
            'record_cancel' => ['type' => 'record_case', 'case_type' => 'cancel_edit', 'text' => self::CANCEL_DONE_TEXT, 'next' => 'end'],

            'edit_what' => ['type' => 'choice', 'field' => 'edit_kind', 'text' => self::EDIT_WHAT_TEXT, 'options' => [
                // «القطع اللي في الأوردر» is 21 characters: Messenger cuts button titles at 20.
                ['value' => 'items', 'title' => 'القطع في الأوردر', 'synonyms' => ['القطع', 'قطعه', 'قطعة', 'المنتج', 'مقاس', 'لون', 'ابدل', 'اشيل'], 'next' => 'edit_items'],
                ['value' => 'address', 'title' => 'العنوان', 'synonyms' => ['عنوان', 'العنوان', 'المكان'], 'next' => 'edit_address'],
                ['value' => 'phone', 'title' => 'رقم الموبايل', 'synonyms' => ['موبايل', 'رقم', 'تليفون', 'الرقم'], 'next' => 'edit_phone'],
            ]],
            'edit_items' => ['type' => 'order_items', 'text' => self::EDIT_ITEMS_TEXT, 'return_rules' => false, 'next' => 'item_changes'],
            'item_changes' => ['type' => 'item_changes', 'next' => 'record_edit'],
            'edit_address' => ['type' => 'text', 'field' => 'new_address', 'text' => self::NEW_ADDRESS_TEXT, 'next' => 'record_edit'],
            'edit_phone' => ['type' => 'phone', 'field' => 'new_phone', 'text' => self::NEW_PHONE_TEXT, 'next' => 'record_edit'],
            'record_edit' => ['type' => 'record_case', 'case_type' => 'cancel_edit', 'text' => self::EDIT_DONE_TEXT, 'next' => 'end'],
        ]];
    }

    public static function complaintDefinition(): array
    {
        return ['start' => 'type', 'steps' => [
            'type' => ['type' => 'choice', 'field' => 'complaint_type', 'text' => self::COMPLAINT_ASK_TEXT, 'options' => [
                ['value' => 'branch', 'title' => 'فرع', 'synonyms' => ['فرع', 'الفرع', 'البياعة', 'الموظفة']],
                ['value' => 'delivery', 'title' => 'شحن وتوصيل', 'synonyms' => ['شحن', 'توصيل', 'المندوب', 'اتأخر']],
                ['value' => 'product', 'title' => 'منتج', 'synonyms' => ['منتج', 'جودة', 'خامة']],
                ['value' => 'service', 'title' => 'خدمة العملاء', 'synonyms' => ['خدمة العملاء', 'محدش بيرد', 'الرد']],
                ['value' => 'other', 'title' => 'حاجة تانية', 'synonyms' => ['تاني', 'حاجة تانية']],
            ], 'branches' => [
                ['field' => 'complaint_type', 'in' => ['branch'], 'next' => 'branch'],
                ['field' => 'complaint_type', 'in' => ['delivery', 'product'], 'next' => 'order'],
            ], 'next' => 'contact'],
            'branch' => ['type' => 'branch', 'field' => 'branch', 'text' => self::COMPLAINT_BRANCH_TEXT, 'next' => 'visit_date'],
            'visit_date' => ['type' => 'choice', 'field' => 'visit_date', 'text' => self::VISIT_DATE_TEXT, 'allow_text' => true, 'options' => [
                ['value' => 'today', 'title' => 'النهارده', 'synonyms' => ['النهارده', 'النهاردة', 'انهارده', 'today']],
                ['value' => 'yesterday', 'title' => 'امبارح', 'synonyms' => ['امبارح', 'امبارحه', 'امس', 'yesterday']],
                ['value' => 'days_ago', 'title' => 'من كام يوم', 'synonyms' => ['من كام يوم', 'من يومين', 'من كذا يوم']],
            ], 'next' => 'contact'],
            'order' => ['type' => 'order', 'field' => 'order', 'text' => self::ASK_ORDER_TEXT, 'verify_owner' => true, 'branches' => [
                // The order's pieces as cards (owner, 2026-09-22): which one the complaint is about; she may skip.
                ['field' => 'order_verified', 'in' => [true], 'next' => 'complaint_items'],
            ], 'next' => 'contact'],
            'complaint_items' => ['type' => 'order_items', 'text' => self::COMPLAINT_ITEMS_TEXT, 'return_rules' => false, 'optional' => true, 'pick_button' => self::COMPLAINT_PICK_BUTTON, 'next' => 'contact'],
            // Skipped when the order above proved who she is (its name and mobile are used).
            'contact' => ['type' => 'contact', 'next' => 'description'],
            'description' => ['type' => 'text', 'field' => 'description', 'text' => self::DESCRIPTION_TEXT, 'photos' => true, 'next' => 'record'],
            'record' => ['type' => 'record_case', 'case_type' => 'complaint', 'text' => self::COMPLAINT_DONE_TEXT, 'next' => 'end'],
        ]];
    }

    public static function branchesDefinition(): array
    {
        return ['start' => 'list', 'steps' => [
            'list' => ['type' => 'branches_list', 'text' => self::BRANCHES_ASK_TEXT, 'next' => 'more'],
            // The main-menu button comes with every choice: [فرع في منطقة تانية] [القائمة الرئيسية].
            'more' => ['type' => 'choice', 'field' => 'branches_more', 'text' => self::BRANCHES_MORE_TEXT, 'options' => [
                ['value' => 'other_area', 'title' => 'فرع في منطقة تانية', 'synonyms' => ['منطقة تانية', 'منطقه تانيه', 'فرع تاني', 'فروع تانية'], 'next' => 'list'],
            ]],
        ]];
    }

    /** The definitions as seeded on 2026-09-17 (before these flows were rebuilt). @return array<string, array> */
    public static function legacyDefinitions(): array
    {
        return [
            'complaint' => ['start' => 'type', 'steps' => [
                'type' => ['type' => 'choice', 'field' => 'complaint_type', 'text' => 'آسفين جدًا لده 🙏 الشكوى بخصوص إيه؟', 'options' => [
                    ['value' => 'branch', 'title' => 'فرع', 'synonyms' => ['فرع', 'الفرع', 'البياعة', 'الموظفة']],
                    ['value' => 'delivery', 'title' => 'شحن وتوصيل', 'synonyms' => ['شحن', 'توصيل', 'المندوب', 'اتأخر']],
                    ['value' => 'product', 'title' => 'منتج', 'synonyms' => ['منتج', 'جودة', 'خامة']],
                    ['value' => 'service', 'title' => 'خدمة العملاء', 'synonyms' => ['خدمة العملاء', 'محدش بيرد', 'الرد']],
                    ['value' => 'other', 'title' => 'حاجة تانية', 'synonyms' => ['تاني', 'حاجة تانية']],
                ], 'branches' => [
                    ['field' => 'complaint_type', 'in' => ['branch'], 'next' => 'branch'],
                    ['field' => 'complaint_type', 'in' => ['delivery', 'product'], 'next' => 'order'],
                ], 'next' => 'name'],
                'branch' => ['type' => 'branch', 'field' => 'branch', 'text' => 'الفرع في أنهي منطقة؟', 'next' => 'visit_date'],
                'visit_date' => ['type' => 'text', 'field' => 'visit_date', 'text' => 'كانت الزيارة إمتى تقريبًا؟', 'next' => 'name'],
                'order' => ['type' => 'order', 'field' => 'order', 'text' => 'ممكن رقم الأوردر أو رقم الموبايل اللي اتعمل بيه الأوردر؟', 'next' => 'name'],
                'name' => ['type' => 'name', 'field' => 'name', 'text' => 'ممكن اسم حضرتك؟', 'next' => 'phone'],
                'phone' => ['type' => 'phone', 'field' => 'phone', 'text' => 'ورقم موبايل نتواصل مع حضرتك عليه؟ 📞', 'next' => 'description'],
                'description' => ['type' => 'text', 'field' => 'description', 'text' => 'احكيلي حصل إيه بالتفصيل عشان نقدر نساعد حضرتك 🙏', 'next' => 'summary'],
                'summary' => ['type' => 'summary', 'text' => 'ده ملخص الشكوى:', 'next' => 'record'],
                'record' => ['type' => 'record_case', 'case_type' => 'complaint', 'script' => 'flow_complaint_recorded', 'next' => 'end'],
            ]],
            'cancel_edit' => ['start' => 'order', 'steps' => [
                'order' => ['type' => 'order', 'field' => 'order', 'text' => 'ممكن رقم الأوردر أو رقم الموبايل اللي اتعمل بيه الأوردر؟ 🌸', 'next' => 'request'],
                'request' => ['type' => 'choice', 'field' => 'request', 'text' => 'حضرتك عايزة تلغي الأوردر ولا تعدل فيه؟', 'options' => [
                    ['value' => 'cancel', 'title' => 'إلغاء الأوردر', 'synonyms' => ['الغاء', 'الغي', 'cancel']],
                    ['value' => 'edit', 'title' => 'تعديل الأوردر', 'synonyms' => ['تعديل', 'اعدل', 'اغير', 'edit']],
                ], 'branches' => [['field' => 'request', 'in' => ['edit'], 'next' => 'edit_details']], 'next' => 'summary'],
                'edit_details' => ['type' => 'text', 'field' => 'edit_details', 'text' => 'عايزة تعدلي إيه بالظبط؟ (المقاس، اللون، العنوان…)', 'next' => 'summary'],
                'summary' => ['type' => 'summary', 'text' => 'ده ملخص طلب حضرتك:', 'next' => 'record'],
                'record' => ['type' => 'record_case', 'case_type' => 'cancel_edit', 'script' => 'flow_cancel_recorded', 'next' => 'end'],
            ]],
            'branches' => ['start' => 'list', 'steps' => [
                'list' => ['type' => 'branches_list', 'text' => 'حضرتك في أنهي منطقة؟ اختاري أو اكتبي اسم المنطقة 👇', 'next' => 'end'],
            ]],
        ];
    }

    /**
     * Publishes the three flows and adds the missing scripts of flows 3–7.
     *
     * @return array<string, 'published'|'skipped'>
     */
    public static function run(): array
    {
        $results = [];

        foreach (self::definitions() as $key => $definition) {
            $results[$key] = ReturnFlowUpgrade::publish($key, $definition, self::NOTES[$key]);
        }

        self::seedScripts();

        return $results;
    }

    /** Inserts the scripts that are missing (an owner edit is never overwritten). */
    public static function seedScripts(): void
    {
        if (! Schema::hasTable('bot_knowledge_entries')) {
            return;
        }

        $now = now();
        $sort = (int) DB::table('bot_knowledge_entries')->max('sort');

        foreach (self::SCRIPT_KEYS as $key) {
            $script = FlowScripts::all()[$key] ?? null;

            if ($script === null || DB::table('bot_knowledge_entries')->where('key', 'script.'.$key)->exists()) {
                continue;
            }

            $sort += 10;
            DB::table('bot_knowledge_entries')->insert([
                'key' => 'script.'.$key,
                'title' => $script['title'],
                'body' => $script['body'],
                'is_active' => true,
                'is_template' => false,
                'sort' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
