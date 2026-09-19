<?php

use App\Cases\CaseSummary;
use App\Http\Resources\SupportCaseResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\SupportCase;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

function csCase(array $attrs): SupportCase
{
    $customer = Customer::factory()->create(['name' => 'Mona FB', 'phone' => '01099999999']);

    return SupportCase::factory()->create(['customer_id' => $customer->id] + $attrs);
}

function csOrder(): Order
{
    $order = Order::factory()->create([
        'order_number' => '1038', 'total' => 1250, 'placed_at' => CarbonImmutable::parse('2026-08-28 10:00', 'Africa/Cairo'),
    ]);
    $variant = ProductVariant::factory()->create(['title' => 'M']);
    OrderItem::factory()->create(['order_id' => $order->id, 'variant_id' => $variant->id, 'title' => 'فستان ستان أسود', 'qty' => 1]);

    return $order;
}

it('builds the return refund summary with the order, its items, photos and the team action', function () {
    $order = csOrder();
    $case = csCase([
        'type' => 'return_exchange', 'priority' => 'medium', 'order_id' => $order->id, 'order_number' => '#1038',
        'data' => [
            'order_number' => '#1038', 'order_id' => $order->id, 'order_status_key' => 'confirmed',
            'reason' => 'size', 'reason_title' => 'المقاس مش مظبوط', 'request' => 'refund', 'request_title' => 'استرجاع المبلغ',
            'product_photo' => [5], 'name' => 'منى أحمد', 'phone' => '01012345678',
        ],
    ]);

    expect(CaseSummary::text($case))->toBe(<<<TXT
📋 حالة #{$case->id} — مرتجع/استبدال — أولوية متوسطة

👤 العميل
منى أحمد · 01012345678

📦 الأوردر #1038
بتاريخ 28/8 · اتأكد وجاري تجهيزه · 1,250 ج.م
المنتجات: فستان ستان أسود (M) × 1

📝 الطلب
السبب: المقاس مش مظبوط
المطلوب: استرجاع المبلغ

📎 المرفقات
صورة المنتج ✅

⚠️ تنبيهات
مفيش

➡️ المطلوب من الفريق
التواصل مع العميلة وترتيب استلام القطعة ورد المبلغ
TXT);
});

it('marks missing photos, lists more than five items and shows policy notes for a defective exchange', function () {
    $order = csOrder();
    OrderItem::factory()->count(6)->create(['order_id' => $order->id, 'variant_id' => null, 'title' => 'طرحة', 'qty' => 2]);
    $case = csCase([
        'type' => 'return_exchange', 'priority' => 'high', 'order_id' => $order->id, 'order_number' => '#1038',
        'data' => ['order_id' => $order->id, 'order_status_key' => 'delivered', 'reason' => 'defective', 'request' => 'exchange', 'product_photo_missing' => true, 'defect_photo_missing' => true],
        'policy_notes' => ['غالبًا عدى 14 يوم من الاستلام (الأوردر بتاريخ 28/8)'],
    ]);

    expect(CaseSummary::text($case))->toBe(<<<TXT
📋 حالة #{$case->id} — مرتجع/استبدال — أولوية عالية

👤 العميل
Mona FB · 01099999999

📦 الأوردر #1038
بتاريخ 28/8 · اتسلم · 1,250 ج.م
المنتجات: فستان ستان أسود (M) × 1، طرحة × 2، طرحة × 2، طرحة × 2، طرحة × 2، و 2 منتجات تانية

📝 الطلب
السبب: بايظ / فيه عيب
المطلوب: استبدال

📎 المرفقات
صورة المنتج — · صورة العيب —

⚠️ تنبيهات
غالبًا عدى 14 يوم من الاستلام (الأوردر بتاريخ 28/8)

➡️ المطلوب من الفريق
التواصل مع العميلة وترتيب استبدال القطعة
TXT);
});

it('builds a branch complaint without an order or attachments section', function () {
    $case = csCase([
        'type' => 'complaint', 'priority' => 'high',
        'data' => ['complaint_type' => 'branch', 'complaint_type_title' => 'فرع', 'branch_name' => 'فرع عباس العقاد', 'visit_date' => 'امبارح',
            'name' => 'منى', 'phone' => '01012345678', 'description' => 'البياعة كانت مش لطيفة'],
    ]);

    expect(CaseSummary::text($case))->toBe(<<<TXT
📋 حالة #{$case->id} — شكوى — أولوية عالية

👤 العميل
منى · 01012345678

📝 الطلب
النوع: فرع
الفرع: فرع عباس العقاد
تاريخ الزيارة: امبارح
التفاصيل: البياعة كانت مش لطيفة

⚠️ تنبيهات
مفيش

➡️ المطلوب من الفريق
التواصل مع العميل ومتابعة الشكوى وحلها
TXT);
});

it('builds a cancel/edit summary with the edit details and the window note', function () {
    $order = csOrder();
    $case = csCase([
        'type' => 'cancel_edit', 'order_id' => $order->id, 'order_number' => '#1038',
        'data' => ['order_id' => $order->id, 'order_status_key' => 'confirmed', 'request' => 'edit', 'request_title' => 'تعديل الأوردر', 'edit_details' => 'عايزة المقاس L'],
        'policy_notes' => ['باقي على مهلة الإلغاء/التعديل: 90 دقيقة'],
    ]);

    expect(CaseSummary::sections($case))->toHaveCount(5)
        ->and(CaseSummary::text($case))->toContain(<<<'TXT'
📝 الطلب
المطلوب: تعديل الأوردر
التعديل: عايزة المقاس L

⚠️ تنبيهات
باقي على مهلة الإلغاء/التعديل: 90 دقيقة

➡️ المطلوب من الفريق
مراجعة الأوردر وتنفيذ التعديل المطلوب لو لسه في المهلة
TXT);
});

it('shows only the typed order text when the order was not found', function () {
    $case = csCase([
        'type' => 'cancel_edit',
        'data' => ['order_ref_text' => 'مش فاكرة', 'request' => 'cancel'],
    ]);

    expect(CaseSummary::text($case))->toBe(<<<TXT
📋 حالة #{$case->id} — إلغاء/تعديل أوردر — أولوية متوسطة

👤 العميل
Mona FB · 01099999999

📦 الأوردر: مش فاكرة (مش لاقيينه في السيستم)

📝 الطلب
المطلوب: إلغاء

⚠️ تنبيهات
مفيش

➡️ المطلوب من الفريق
مراجعة الأوردر وإلغاؤه لو لسه في المهلة
TXT);
});

it('builds a delivery follow-up summary with the shipping status', function () {
    $order = csOrder();
    $case = csCase([
        'type' => 'delivery_followup', 'priority' => 'high', 'order_id' => $order->id, 'order_number' => '#1038', 'customer_id' => null,
        'data' => ['order_id' => $order->id, 'order_number' => '#1038', 'order_status_key' => 'returned', 'order_status_line' => 'هراجع الأوردر رقم #1038 مع الفريق وهرد على حضرتك'],
    ]);
    $case->update(['customer_id' => null]);

    expect(CaseSummary::text($case->fresh()))->toBe(<<<TXT
📋 حالة #{$case->id} — متابعة شحن — أولوية عالية

👤 العميل
غير معروف

📦 الأوردر #1038
بتاريخ 28/8 · رجع لينا (مرتجع) · 1,250 ج.م
المنتجات: فستان ستان أسود (M) × 1

📝 الطلب
حالة الشحن: رجع لينا (مرتجع)

⚠️ تنبيهات
مفيش

➡️ المطلوب من الفريق
متابعة الشحنة مع شركة الشحن والرد على العميل
TXT);
});

it('exposes the header and sections on the resource', function () {
    $case = csCase(['type' => 'complaint', 'data' => ['complaint_type' => 'service', 'description' => 'محدش بيرد']]);

    $json = (new SupportCaseResource($case))->resolve(Request::create('/'));

    expect($json['summary_header'])->toBe("📋 حالة #{$case->id} — شكوى — أولوية متوسطة")
        ->and($json)->not->toHaveKey('summary_lines')
        ->and(array_column($json['summary_sections'], 'key'))->toBe(['customer', 'request', 'alerts', 'team_action'])
        ->and($json['summary_sections'][1])->toBe(['key' => 'request', 'icon' => '📝', 'title' => 'الطلب', 'lines' => ['النوع: خدمة العملاء', 'التفاصيل: محدش بيرد']]);
});
