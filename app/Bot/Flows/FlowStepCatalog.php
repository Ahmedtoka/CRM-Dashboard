<?php

namespace App\Bot\Flows;

/**
 * Step-type metadata for the flow designer editor (Task 2, design doc §2):
 * one entry per `FlowDefinition::TYPES`, describing how the palette and the
 * step editor should present it. `fields` mirrors what `FlowDefinition`'s
 * validator actually reads for that type (see `FIELD_REQUIRED_TYPES`, the
 * `record_case`/`script` checks and the seeded flows in `FlowDefinitions`),
 * so the editor never offers a field the engine ignores.
 */
final class FlowStepCatalog
{
    /**
     * @return array<string, array{label_ar:string, icon:string, color:string, fields: list<string>, options: 'none'|'choice'|'menu'|'summary', has_next: bool}>
     */
    public static function all(): array
    {
        return [
            'menu' => self::entry('قائمة خيارات', 'ListChecks', 'violet', ['text'], 'menu', false),
            'choice' => self::entry('اختيار من متعدد', 'LayoutList', 'indigo', ['text', 'field'], 'choice', true),
            'text' => self::entry('نص حر', 'MessageSquareText', 'sky', ['text', 'field'], 'none', true),
            'name' => self::entry('اسم العميلة', 'IdCard', 'teal', ['text', 'field'], 'none', true),
            'phone' => self::entry('رقم الموبايل', 'Phone', 'teal', ['text', 'field'], 'none', true),
            'photo' => self::entry('صورة', 'Camera', 'amber', ['text', 'field'], 'none', true),
            'order' => self::entry('رقم الأوردر', 'Package', 'amber', ['text', 'field'], 'none', true),
            'branch' => self::entry('اختيار فرع', 'MapPin', 'emerald', ['text', 'field'], 'none', true),
            'branches_list' => self::entry('قائمة الفروع', 'Store', 'emerald', ['text'], 'none', true),
            'status' => self::entry('حالة الأوردر', 'Truck', 'sky', [], 'none', true),
            'summary' => self::entry('ملخص الطلب', 'ClipboardCheck', 'slate', ['text'], 'summary', true),
            'record_case' => self::entry('تسجيل حالة', 'FilePlus2', 'rose', ['case_type', 'script'], 'none', true),
            'script' => self::entry('نص من السكريبتات', 'FileText', 'orange', ['script'], 'none', true),
            'handover' => self::entry('تحويل لموظف', 'UserRound', 'pink', [], 'none', false),
            'end' => self::entry('نهاية الفلو', 'CircleStop', 'slate', [], 'none', false),
        ];
    }

    /** @param  list<string>  $fields @return array{label_ar:string, icon:string, color:string, fields: list<string>, options: 'none'|'choice'|'menu'|'summary', has_next: bool} */
    private static function entry(string $labelAr, string $icon, string $color, array $fields, string $options, bool $hasNext): array
    {
        return [
            'label_ar' => $labelAr,
            'icon' => $icon,
            'color' => $color,
            'fields' => $fields,
            'options' => $options,
            'has_next' => $hasNext,
        ];
    }
}
