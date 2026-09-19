<?php

namespace App\Cases;

use App\Bot\Flow\Orders\OrderStatusText;
use App\Bot\Flows\FlowDefinitions;
use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\ReturnFlowUpgrade;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupportCase;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The organised Arabic summary of a case (owner-approved format), used as-is
 * by the conversation note, the stored `summary` column, the case card and the
 * cases page: a header line, then sections for the customer, the order, the
 * request, attachments, alerts and what the team should do. A section with no
 * content is left out, except the alerts one, which says "مفيش".
 */
final class CaseSummary
{
    private const PRIORITY_WORDS = ['high' => 'عالية', 'medium' => 'متوسطة'];

    private const MAX_ITEMS = 5;

    /** Shopify's placeholder title for a product that has no options. */
    private const DEFAULT_VARIANT_TITLES = ['default title', 'default'];

    public static function header(SupportCase $case): string
    {
        $priority = self::PRIORITY_WORDS[$case->priority] ?? self::PRIORITY_WORDS['medium'];

        return "📋 حالة #{$case->id} — {$case->typeLabel()} — أولوية {$priority}";
    }

    /** @return list<array{key:string, icon:string, title:string, lines:list<string>}> */
    public static function sections(SupportCase $case): array
    {
        $data = is_array($case->data) ? $case->data : [];

        return array_values(array_filter([
            self::customer($case, $data),
            self::order($case, $data),
            self::section('items', '🛍️', 'القطع المطلوبة', self::selectedItemLines($data)),
            self::section('request', '📝', 'الطلب', self::requestLines($case, $data)),
            self::section('attachments', '📎', 'المرفقات', self::attachmentLines($case, $data)),
            self::section('alerts', '⚠️', 'تنبيهات', array_values(array_filter(array_map('strval', (array) $case->policy_notes), 'filled')) ?: ['مفيش']),
            self::section('team_action', '➡️', 'المطلوب من الفريق', [self::teamAction($case, $data)]),
        ]));
    }

    /** The full note text: header and sections, blank-line separated. */
    public static function text(SupportCase $case): string
    {
        $blocks = [self::header($case)];

        foreach (self::sections($case) as $s) {
            $blocks[] = implode("\n", array_merge([$s['icon'].' '.$s['title']], $s['lines']));
        }

        return implode("\n\n", $blocks);
    }

    /** One line for a notification: the header and the first request line. */
    public static function excerpt(SupportCase $case): string
    {
        foreach (self::sections($case) as $s) {
            if ($s['key'] === 'request' && $s['lines'] !== []) {
                return self::header($case).' · '.$s['lines'][0];
            }
        }

        return self::header($case);
    }

    /** @return array{key:string, icon:string, title:string, lines:list<string>}|null */
    private static function section(string $key, string $icon, string $title, array $lines): ?array
    {
        return $lines === [] ? null : ['key' => $key, 'icon' => $icon, 'title' => $title, 'lines' => $lines];
    }

    private static function customer(SupportCase $case, array $data): array
    {
        $customer = $case->customer_id !== null ? $case->customer : null;
        $name = self::str($data['name'] ?? null) ?? self::str($customer?->name) ?? 'غير معروف';
        $phone = self::str($data['phone'] ?? null) ?? self::str($customer?->phone) ?? self::str($customer?->normalized_phone);

        return ['key' => 'customer', 'icon' => '👤', 'title' => 'العميل', 'lines' => [$phone !== null ? "{$name} · {$phone}" : $name]];
    }

    private static function order(SupportCase $case, array $data): ?array
    {
        $order = $case->order_id !== null ? $case->order : null;
        $number = self::str($order?->shopify_order_name) ?? self::str($order?->order_number) ?? self::str($case->order_number) ?? self::str($data['order_number'] ?? null);

        if ($order === null && $number === null) {
            $typed = self::str($data['order_ref_text'] ?? null);

            return $typed === null ? null : ['key' => 'order', 'icon' => '📦', 'title' => "الأوردر: {$typed} (مش لاقيينه في السيستم)", 'lines' => []];
        }

        $details = array_values(array_filter([
            ($date = self::placedAt($order, $data)) !== null ? 'بتاريخ '.$date->setTimezone(OrderStatusText::TIMEZONE)->format('j/n') : null,
            self::statusLabel($order, $data),
            $order !== null && $order->total !== null ? self::money((float) $order->total).' ج.م' : null,
        ]));
        $lines = $details === [] ? [] : [implode(' · ', $details)];

        if ($order !== null && ($items = self::itemsLine($order)) !== null) {
            $lines[] = $items;
        }

        return ['key' => 'order', 'icon' => '📦', 'title' => $number !== null ? 'الأوردر #'.ltrim($number, '#') : 'الأوردر', 'lines' => $lines];
    }

    /**
     * The items she picked in the flow (spec 2026-09-19 §2), one line each:
     * "فستان ليلى — أسود / M × 1 — 850 ج.م (استبدال بس)".
     *
     * @return list<array{line_item_id:?int, title:string, variant:?string, qty:int, price:?float, exchange_only:bool}>
     */
    public static function selectedItems(array $data): array
    {
        $rows = [];

        foreach ((array) ($data['selected_items'] ?? []) as $i) {
            $title = is_array($i) ? self::str($i['title'] ?? null) : null;

            if ($title === null) {
                continue;
            }

            $rows[] = [
                'line_item_id' => is_numeric($i['line_item_id'] ?? null) ? (int) $i['line_item_id'] : null,
                'title' => $title,
                'variant' => self::str($i['variant'] ?? null),
                'qty' => max(1, (int) ($i['qty'] ?? 1)),
                'price' => is_numeric($i['price'] ?? null) ? (float) $i['price'] : null,
                'exchange_only' => ($i['exchange_only'] ?? false) === true,
            ];
        }

        return $rows;
    }

    /** @return list<string> */
    private static function selectedItemLines(array $data): array
    {
        return array_map(fn (array $r) => $r['title']
            .($r['variant'] !== null ? ' — '.$r['variant'] : '')
            .' × '.$r['qty']
            .($r['price'] !== null ? ' — '.self::money($r['price']).' ج.م' : '')
            .($r['exchange_only'] ? ' (استبدال بس)' : ''), self::selectedItems($data));
    }

    /** @return list<string> */
    private static function requestLines(SupportCase $case, array $data): array
    {
        $flow = $case->type;
        $lines = match ($case->type) {
            'return' => [
                'الطلب: مرتجع',
                self::labelled('السبب', self::optionTitle($flow, 'reason', $data)),
            ],
            'exchange' => [
                'الطلب: استبدال',
                self::labelled('السبب', self::optionTitle($flow, 'reason', $data)),
                ...self::exchangeLines($data),
            ],
            'return_exchange' => [
                self::labelled('السبب', self::optionTitle($flow, 'reason', $data)),
                self::labelled('المطلوب', self::optionTitle($flow, 'request', $data)),
            ],
            'complaint' => [
                self::labelled('النوع', self::optionTitle($flow, 'complaint_type', $data)),
                self::labelled('الفرع', self::str($data['branch_name'] ?? null)),
                self::labelled('تاريخ الزيارة', self::optionTitle($flow, 'visit_date', $data)),
                self::labelled('التفاصيل', self::str($data['description'] ?? null)),
            ],
            'cancel_edit' => [
                self::labelled('المطلوب', self::optionTitle($flow, 'request', $data)),
                self::labelled('سبب الإلغاء', self::str($data['cancel_reason'] ?? null)),
                self::labelled('نوع التعديل', self::optionTitle($flow, 'edit_kind', $data)),
                ...FlowPrompter::changeLines($data['item_changes'] ?? []),
                self::labelled('العنوان الجديد', self::str($data['new_address'] ?? null)),
                self::labelled('الموبايل الجديد', self::str($data['new_phone'] ?? null)),
                self::labelled('التعديل', self::str($data['edit_details'] ?? null)),
            ],
            'delivery_followup' => [self::labelled('حالة الشحن', self::statusLabel($case->order_id !== null ? $case->order : null, $data))],
            default => [],
        };

        return array_values(array_filter($lines));
    }

    /**
     * The product she wants in exchange (the `product_link` step, 2026-09-19), or null.
     *
     * @return array{title:string, handle:?string, url:?string, price:?float, image:?string, variant_title:?string}|null
     */
    public static function exchangeProduct(array $data): ?array
    {
        $p = $data['exchange_product'] ?? null;
        $title = is_array($p) ? self::str($p['title'] ?? null) : null;

        if ($title === null) {
            return null;
        }

        $url = self::str($p['url'] ?? null);

        return [
            'title' => $title,
            'handle' => self::str($p['handle'] ?? null),
            'url' => $url !== null ? (preg_match('~^https?://~i', $url) ? $url : 'https://'.$url) : null,
            'price' => is_numeric($p['price'] ?? null) ? (float) $p['price'] : null,
            'image' => self::str($p['image'] ?? null),
            'variant_title' => self::str($p['variant_title'] ?? null),
        ];
    }

    /** "طلب استبدال: فستان ليلى (أسود / M) × 1 ← عباية كتان — 1,200 ج.م — https://…" (the conversation note). */
    public static function exchangeNote(array $data): string
    {
        $items = FlowPrompter::itemsText($data['selected_items'] ?? []);
        $items = $items !== '' ? $items : 'القطعة';
        $product = self::exchangeProduct($data);

        if ($product !== null) {
            $parts = [$product['title'].($product['variant_title'] !== null ? " ({$product['variant_title']})" : '')];

            if ($product['price'] !== null) {
                $parts[] = self::money($product['price']).' ج.م';
            }

            if ($product['url'] !== null) {
                $parts[] = $product['url'];
            }

            return "طلب استبدال: {$items} ← ".implode(' — ', $parts);
        }

        $typed = self::str($data['exchange_product_text'] ?? null);

        if ($typed !== null) {
            return "طلب استبدال: {$items} ← المنتج مش متحدد، العميلة كتبت: «{$typed}»";
        }

        return ! empty($data['exchange_product_photo'])
            ? "طلب استبدال: {$items} ← العميلة بعتت صورة للمنتج البديل (في الصور)"
            : "طلب استبدال: {$items} ← المنتج البديل مش متحدد";
    }

    /**
     * The internal note of an edit request (the owner's cancel/edit flow, 2026-09-19): every change
     * the team makes on the order. Null for a cancel request, or when nothing was changed.
     */
    public static function editNote(array $data): ?string
    {
        if (($data['request'] ?? null) !== 'edit') {
            return null;
        }

        $lines = array_values(array_filter([
            ...FlowPrompter::changeLines($data['item_changes'] ?? []),
            ($address = self::str($data['new_address'] ?? null)) !== null ? '📍 العنوان الجديد: '.$address : null,
            ($phone = self::str($data['new_phone'] ?? null)) !== null ? '📞 الموبايل الجديد: '.$phone : null,
        ]));

        if ($lines === []) {
            return null;
        }

        $number = self::str($data['order_number'] ?? null);

        return '✏️ تعديلات مطلوبة على '.($number !== null ? 'أوردر #'.ltrim($number, '#') : 'الأوردر').":\n".implode("\n", $lines);
    }

    /** @return list<string> */
    private static function exchangeLines(array $data): array
    {
        $product = self::exchangeProduct($data);

        if ($product !== null) {
            return array_values(array_filter([
                'البديل: '.$product['title']
                    .($product['variant_title'] !== null ? ' — '.$product['variant_title'] : '')
                    .($product['price'] !== null ? ' — '.self::money($product['price']).' ج.م' : ''),
                $product['url'] !== null ? 'اللينك: '.$product['url'] : null,
            ]));
        }

        $typed = self::str($data['exchange_product_text'] ?? null);

        return [match (true) {
            $typed !== null => "البديل: مش متحدد — العميلة كتبت «{$typed}»",
            ! empty($data['exchange_product_photo']) => 'البديل: العميلة بعتت صورة للمنتج',
            default => 'البديل: مش متحدد',
        }];
    }

    /** @return list<string> */
    private static function attachmentLines(SupportCase $case, array $data): array
    {
        if ($case->type === 'return') {
            return ['صورة القطعة '.(! empty($data['product_photo']) ? '✅' : '— (مبعتتش صورة)')];
        }

        if ($case->type === 'exchange') {
            return ! empty($data['exchange_product_photo']) ? ['صورة المنتج البديل ✅'] : [];
        }

        if ($case->type === 'complaint') {
            return ! empty($data['description_photo']) ? ['صور من العميلة ✅'] : [];
        }

        if ($case->type === 'cancel_edit') {
            return collect((array) ($data['item_changes'] ?? []))->contains(fn ($c) => is_array($c) && ! empty($c['photo'])) ? ['صورة للمنتج البديل ✅'] : [];
        }

        if ($case->type !== 'return_exchange') {
            return [];
        }

        $fields = ['product_photo' => 'صورة المنتج'];

        if (($data['reason'] ?? null) === 'defective') {
            $fields['defect_photo'] = 'صورة العيب';
        }

        $parts = [];

        foreach ($fields as $field => $label) {
            $parts[] = $label.' '.(! empty($data[$field]) ? '✅' : '—');
        }

        return [implode(' · ', $parts)];
    }

    private static function teamAction(SupportCase $case, array $data): string
    {
        $request = $data['request'] ?? null;

        return match ($case->type) {
            'return' => 'مراجعة القطعة وترتيب المندوب لاستلام المرتجع وإبلاغ العميلة بموعده',
            'exchange' => 'التأكد من توفر المنتج البديل ومقاسه، والتواصل مع العميلة لتأكيد الاستبدال والإرسال',
            'return_exchange' => match ($request) {
                'refund' => 'التواصل مع العميلة وترتيب استلام القطعة ورد المبلغ',
                'exchange' => 'التواصل مع العميلة وترتيب استبدال القطعة',
                default => 'التواصل مع العميلة ومراجعة طلب المرتجع',
            },
            'complaint' => 'التواصل مع العميل ومتابعة الشكوى وحلها',
            'cancel_edit' => isset($data['order_editable']) ? match ($request) {
                'cancel' => 'إلغاء الأوردر قبل ما يتشحن وتأكيد الإلغاء مع العميلة',
                'edit' => 'تنفيذ التعديلات على الأوردر قبل ما يتشحن وتأكيدها مع العميلة',
                default => 'مراجعة الأوردر وتنفيذ طلب العميلة قبل ما يتشحن',
            } : match ($request) {
                'cancel' => 'مراجعة الأوردر وإلغاؤه لو لسه في المهلة',
                'edit' => 'مراجعة الأوردر وتنفيذ التعديل المطلوب لو لسه في المهلة',
                default => 'مراجعة الأوردر وتنفيذ طلب العميلة لو لسه في المهلة',
            },
            'delivery_followup' => 'متابعة الشحنة مع شركة الشحن والرد على العميل',
            default => 'مراجعة الحالة والتواصل مع العميل',
        };
    }

    private static function placedAt(?Order $order, array $data): ?CarbonImmutable
    {
        $placed = $order?->placed_at ?? $order?->created_at;

        if ($placed !== null) {
            return CarbonImmutable::instance($placed);
        }

        try {
            return filled($data['order_placed_at'] ?? null) ? CarbonImmutable::parse((string) $data['order_placed_at']) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** The short status words from the key the order step saved, else a cancelled order's state. */
    private static function statusLabel(?Order $order, array $data): ?string
    {
        $key = self::str($data['order_status_key'] ?? null);

        if ($key === null && $order?->cancelled_at !== null) {
            $key = 'cancelled';
        }

        return $key !== null ? OrderStatusText::shortLabel($key) : null;
    }

    private static function itemsLine(Order $order): ?string
    {
        $items = $order->loadMissing('items.variant')->items;

        if ($items->isEmpty()) {
            return null;
        }

        $shown = $items->take(self::MAX_ITEMS)->map(function (OrderItem $item) {
            $variant = self::str($item->variant?->title);
            $suffix = $variant !== null && ! in_array(mb_strtolower($variant), self::DEFAULT_VARIANT_TITLES, true) ? " ({$variant})" : '';

            return "{$item->title}{$suffix} × {$item->qty}";
        })->all();

        $rest = $items->count() - self::MAX_ITEMS;

        if ($rest > 0) {
            $shown[] = $rest === 1 ? 'ومنتج تاني' : "و {$rest} منتجات تانية";
        }

        return 'المنتجات: '.implode('، ', $shown);
    }

    /** The option title the flow saved (`<field>_title`), else the seeded option title, else the raw value. */
    private static function optionTitle(string $flow, string $field, array $data): ?string
    {
        $value = self::str($data[$field] ?? null);

        if ($value === null) {
            return null;
        }

        if (($title = self::str($data[$field.'_title'] ?? null)) !== null) {
            return $title;
        }

        $flow = in_array($flow, ['return', 'exchange'], true) ? 'return_exchange' : $flow;
        $steps = FlowDefinitions::all()[$flow]['definition']['steps'] ?? [];

        // Cases recorded by the return flow before 2026-09-19 carry its old fields (e.g. `request`).
        if ($flow === 'return_exchange') {
            $steps = [...array_values($steps), ...array_values(ReturnFlowUpgrade::legacyDefinition()['steps'])];
        }

        foreach ($steps as $step) {
            if (($step['field'] ?? null) !== $field) {
                continue;
            }

            foreach ($step['options'] ?? [] as $option) {
                if ((string) ($option['value'] ?? '') === $value) {
                    return (string) $option['title'];
                }
            }
        }

        return $value;
    }

    private static function labelled(string $label, ?string $value): ?string
    {
        return $value !== null ? "{$label}: {$value}" : null;
    }

    private static function money(float $amount): string
    {
        return number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);
    }

    private static function str(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
