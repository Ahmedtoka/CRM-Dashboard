<?php

namespace App\Cases;

use App\Bot\Flow\Orders\OrderStatusText;
use App\Bot\Flows\FlowDefinitions;
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

    /** @return list<string> */
    private static function requestLines(SupportCase $case, array $data): array
    {
        $flow = $case->type;
        $lines = match ($case->type) {
            'return_exchange' => [
                self::labelled('السبب', self::optionTitle($flow, 'reason', $data)),
                self::labelled('المطلوب', self::optionTitle($flow, 'request', $data)),
            ],
            'complaint' => [
                self::labelled('النوع', self::optionTitle($flow, 'complaint_type', $data)),
                self::labelled('الفرع', self::str($data['branch_name'] ?? null)),
                self::labelled('تاريخ الزيارة', self::str($data['visit_date'] ?? null)),
                self::labelled('التفاصيل', self::str($data['description'] ?? null)),
            ],
            'cancel_edit' => [
                self::labelled('المطلوب', self::optionTitle($flow, 'request', $data)),
                self::labelled('التعديل', self::str($data['edit_details'] ?? null)),
            ],
            'delivery_followup' => [self::labelled('حالة الشحن', self::statusLabel($case->order_id !== null ? $case->order : null, $data))],
            default => [],
        };

        return array_values(array_filter($lines));
    }

    /** @return list<string> */
    private static function attachmentLines(SupportCase $case, array $data): array
    {
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
            'return_exchange' => match ($request) {
                'refund' => 'التواصل مع العميلة وترتيب استلام القطعة ورد المبلغ',
                'exchange' => 'التواصل مع العميلة وترتيب استبدال القطعة',
                default => 'التواصل مع العميلة ومراجعة طلب المرتجع',
            },
            'complaint' => 'التواصل مع العميل ومتابعة الشكوى وحلها',
            'cancel_edit' => match ($request) {
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

        foreach (FlowDefinitions::all()[$flow]['definition']['steps'] ?? [] as $step) {
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
