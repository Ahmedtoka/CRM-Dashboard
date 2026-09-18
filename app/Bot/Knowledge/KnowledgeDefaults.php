<?php

namespace App\Bot\Knowledge;

/**
 * Default policy knowledge entries seeded once by a guarded data migration
 * (spec §4.2). Core keys can be deactivated but never deleted.
 */
final class KnowledgeDefaults
{
    public const CORE_KEYS = ['exchange_policy', 'return_policy', 'shipping_times', 'payment_methods', 'working_hours_text', 'fabric_care', 'store_intro'];

    /** @return list<array{key:string,title:string,body:string,sort:int}> */
    public static function entries(): array
    {
        return [
            ['key' => 'store_intro', 'sort' => 10, 'title' => 'تعريف المتجر', 'body' => 'أهلاً بيكي في متجرنا 🌸 بنبيع ملابس حريمي بتصميمات مختارة، وبنوصل لكل محافظات مصر.'],
            ['key' => 'working_hours_text', 'sort' => 20, 'title' => 'مواعيد العمل', 'body' => 'مواعيدنا يوميًا من 10 ص لحد 10 م.'],
            ['key' => 'payment_methods', 'sort' => 30, 'title' => 'طرق الدفع', 'body' => 'الدفع كاش عند الاستلام، أو أونلاين بلينك دفع بنبعتهولك.'],
            ['key' => 'shipping_times', 'sort' => 40, 'title' => 'مدة التوصيل', 'body' => 'التوصيل من 2 لـ 4 أيام عمل للقاهرة والجيزة، ومن 3 لـ 6 أيام عمل لباقي المحافظات.'],
            ['key' => 'exchange_policy', 'sort' => 50, 'title' => 'سياسة الاستبدال', 'body' => 'الاستبدال خلال 14 يوم من الاستلام بشرط إن القطعة متلبستش وبالتيكت، ومصاريف شحن الإرجاع على العميلة إلا لو فيه عيب في القطعة.'],
            ['key' => 'return_policy', 'sort' => 60, 'title' => 'سياسة الاسترجاع', 'body' => 'استرجاع الفلوس بيكون للقطع اللي فيها عيب أو لو وصلك موديل غلط بس.'],
            ['key' => 'fabric_care', 'sort' => 70, 'title' => 'الخامات والعناية', 'body' => 'يُفضل الغسيل على الهادي بمية باردة ومن غير مبيض، والكي على حرارة متوسطة من الوش التاني.'],
        ];
    }
}
