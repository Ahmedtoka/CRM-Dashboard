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
            // The owner's flow of 2026-09-19 (ReturnFlowUpgrade publishes it on live databases).
            'return_exchange' => ['title_ar' => 'المرتجع والاستبدال', 'definition' => ReturnFlowUpgrade::definition()],
            // The owner's flows of 2026-09-19 (OwnerFlowsUpgrade publishes them on live databases).
            'complaint' => ['title_ar' => 'شكوى', 'definition' => OwnerFlowsUpgrade::complaintDefinition()],
            'cancel_edit' => ['title_ar' => 'إلغاء أو تعديل أوردر', 'definition' => OwnerFlowsUpgrade::cancelEditDefinition()],
            // The owner's flow of 2026-09-19 (TrackingFlowUpgrade publishes it on live databases).
            'order_tracking' => ['title_ar' => 'متابعة أوردر', 'definition' => TrackingFlowUpgrade::definition()],
            'branches' => ['title_ar' => 'الفروع والمواعيد', 'definition' => OwnerFlowsUpgrade::branchesDefinition()],
        ];
    }
}
