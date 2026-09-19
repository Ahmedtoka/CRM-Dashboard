<?php

namespace App\Bot\Flows;

/**
 * The 7 guided flows seeded into `bot_flows` (Task 3, design doc §flows).
 * Exact Arabic texts, keys and titles per the task brief — do not reword.
 */
final class FlowDefinitions
{
    /** @return array<string, array{title_ar:string, definition:array}> */
    public static function all(): array
    {
        return [
            'main_menu' => ['title_ar' => 'القائمة الرئيسية', 'definition' => ['start' => 'menu', 'steps' => [
                'menu' => ['type' => 'menu', 'text' => 'أقدر أساعد حضرتك إزاي؟ اختاري من القائمة 👇', 'options' => [
                    ['title' => 'المرتجع والاستبدال', 'action' => 'flow:return_exchange', 'synonyms' => ['مرتجع', 'استرجاع', 'استبدال', 'ارجع', 'ابدل']],
                    ['title' => 'متابعة أوردر', 'action' => 'flow:order_tracking', 'synonyms' => ['متابعة', 'فين الاوردر', 'تتبع', 'الاوردر']],
                    ['title' => 'شكوى', 'action' => 'flow:complaint', 'synonyms' => ['شكوي', 'شكوى', 'مشكلة']],
                    ['title' => 'الفروع والمواعيد', 'action' => 'flow:branches', 'synonyms' => ['فروع', 'الفروع', 'فرع', 'عنوان', 'مواعيد']],
                    ['title' => 'الموديلات والأسعار', 'action' => 'menu:products', 'synonyms' => ['موديلات', 'اسعار', 'سعر', 'بكام']],
                    ['title' => 'إلغاء أو تعديل أوردر', 'action' => 'flow:cancel_edit', 'synonyms' => ['الغاء', 'تعديل', 'الغي', 'اعدل']],
                    ['title' => 'كلم موظف', 'action' => 'handover', 'synonyms' => ['موظف', 'خدمة العملاء', 'حد يكلمني']],
                ]],
            ]]],
            'products' => ['title_ar' => 'الموديلات والأسعار', 'definition' => ['start' => 'menu', 'steps' => [
                'menu' => ['type' => 'menu', 'text' => 'تحبي تعرفي إيه؟ 👇', 'options' => [
                    ['title' => 'الموديلات والأسعار', 'action' => 'script:availability'],
                    ['title' => 'المقاسات', 'action' => 'script:size'],
                    ['title' => 'الشحن والتوصيل', 'action' => 'script:delivery_time'],
                    ['title' => 'طرق الدفع', 'action' => 'script:payment_info'],
                    ['title' => 'القائمة الرئيسية', 'action' => 'menu:main_menu'],
                ]],
            ]]],
            'return_exchange' => ['title_ar' => 'المرتجع والاستبدال', 'definition' => ['start' => 'policy', 'steps' => [
                'policy' => ['type' => 'script', 'script' => 'flow_return_policy_short', 'next' => 'order'],
                // Spec 2026-09-19: proof of ownership, then the items she picked (ReturnFlowUpgrade moves live flows here).
                'order' => ['type' => 'order', 'field' => 'order', 'text' => 'ممكن رقم الأوردر أو رقم الموبايل اللي اتعمل بيه الأوردر؟ 🌸', 'verify_owner' => true, 'next' => 'order_items'],
                'order_items' => ['type' => 'order_items', 'text' => 'اختاري القطعة اللي عايزة ترجعيها أو تبدليها 👇', 'next' => 'reason'],
                'reason' => ['type' => 'choice', 'field' => 'reason', 'text' => 'إيه سبب المرتجع؟', 'options' => [
                    ['value' => 'defective', 'title' => 'بايظ / فيه عيب', 'synonyms' => ['بايظ', 'عيب', 'مقطوع', 'ديفوه', 'تالف', 'شايط']],
                    ['value' => 'wrong_item', 'title' => 'غلط في الأوردر', 'synonyms' => ['غلط', 'مش اللي طلبته', 'لون تاني']],
                    ['value' => 'missing_item', 'title' => 'قطعة ناقصة', 'synonyms' => ['ناقص', 'ناقصة', 'ناقصه']],
                    ['value' => 'size', 'title' => 'المقاس مش مظبوط', 'synonyms' => ['مقاس', 'كبير', 'صغير', 'واسع', 'ضيق']],
                    ['value' => 'not_liked', 'title' => 'مش عاجبني', 'synonyms' => ['مش عاجبني', 'معجبنيش', 'مش حلو']],
                ], 'next' => 'request'],
                'request' => ['type' => 'choice', 'field' => 'request', 'text' => 'حضرتك عايزة استرجاع المبلغ ولا استبدال؟', 'options' => [
                    ['value' => 'refund', 'title' => 'استرجاع المبلغ', 'synonyms' => ['استرجاع', 'فلوس', 'مبلغ', 'refund']],
                    ['value' => 'exchange', 'title' => 'استبدال', 'synonyms' => ['استبدال', 'ابدل', 'تبديل', 'exchange']],
                ], 'next' => 'product_photo'],
                'product_photo' => ['type' => 'photo', 'field' => 'product_photo', 'text' => 'ممكن صورة واضحة للمنتج؟ 📸', 'next' => 'after_photo'],
                'after_photo' => ['type' => 'script', 'script' => 'flow_photo_received', 'branches' => [['field' => 'reason', 'in' => ['defective'], 'next' => 'defect_photo']], 'next' => 'summary'],
                'defect_photo' => ['type' => 'photo', 'field' => 'defect_photo', 'text' => 'وممكن صورة توضح العيب اللي في المنتج؟ 📸', 'next' => 'summary'],
                'summary' => ['type' => 'summary', 'text' => 'ده ملخص طلب حضرتك:', 'next' => 'record'],
                'record' => ['type' => 'record_case', 'case_type' => 'return_exchange', 'script' => 'flow_return_recorded', 'next' => 'end'],
            ]]],
            'complaint' => ['title_ar' => 'شكوى', 'definition' => ['start' => 'type', 'steps' => [
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
            ]]],
            'cancel_edit' => ['title_ar' => 'إلغاء أو تعديل أوردر', 'definition' => ['start' => 'order', 'steps' => [
                'order' => ['type' => 'order', 'field' => 'order', 'text' => 'ممكن رقم الأوردر أو رقم الموبايل اللي اتعمل بيه الأوردر؟ 🌸', 'next' => 'request'],
                'request' => ['type' => 'choice', 'field' => 'request', 'text' => 'حضرتك عايزة تلغي الأوردر ولا تعدل فيه؟', 'options' => [
                    ['value' => 'cancel', 'title' => 'إلغاء الأوردر', 'synonyms' => ['الغاء', 'الغي', 'cancel']],
                    ['value' => 'edit', 'title' => 'تعديل الأوردر', 'synonyms' => ['تعديل', 'اعدل', 'اغير', 'edit']],
                ], 'branches' => [['field' => 'request', 'in' => ['edit'], 'next' => 'edit_details']], 'next' => 'summary'],
                'edit_details' => ['type' => 'text', 'field' => 'edit_details', 'text' => 'عايزة تعدلي إيه بالظبط؟ (المقاس، اللون، العنوان…)', 'next' => 'summary'],
                'summary' => ['type' => 'summary', 'text' => 'ده ملخص طلب حضرتك:', 'next' => 'record'],
                'record' => ['type' => 'record_case', 'case_type' => 'cancel_edit', 'script' => 'flow_cancel_recorded', 'next' => 'end'],
            ]]],
            'order_tracking' => ['title_ar' => 'متابعة أوردر', 'definition' => ['start' => 'order', 'steps' => [
                'order' => ['type' => 'order', 'field' => 'order', 'text' => 'ممكن رقم الأوردر أو رقم الموبايل اللي اتعمل بيه الأوردر؟ 🌸', 'next' => 'status'],
                'status' => ['type' => 'status', 'next' => 'end'],
            ]]],
            'branches' => ['title_ar' => 'الفروع والمواعيد', 'definition' => ['start' => 'list', 'steps' => [
                'list' => ['type' => 'branches_list', 'text' => 'حضرتك في أنهي منطقة؟ اختاري أو اكتبي اسم المنطقة 👇', 'next' => 'end'],
            ]]],
        ];
    }
}
