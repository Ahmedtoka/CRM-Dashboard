<?php

namespace App\Confirmations\WhatsApp;

use App\Models\Order;
use App\Models\OrderItem;

/**
 * The variables of the confirmation and reminder templates (spec 2026-10-16), in template order:
 * BODY[0] is {{1}}, BODY[1] is {{2}}, and so on. The sender (W2) fills them from the order and the
 * template tooling (W5) writes the template copy and its examples from the same lists, so the two
 * never drift. Both templates (`order_confirm_ar`, `order_reminder_ar`) use the same variables and
 * the same three quick-reply buttons, in BUTTONS order (button 0 confirm, 1 edit, 2 cancel).
 */
final class TemplateParams
{
    /** Body variables, in {{n}} order. */
    public const BODY = ['first_name', 'order_number', 'items', 'total', 'address', 'payment'];

    /** Quick-reply buttons, in index order: the action each one's payload carries. */
    public const BUTTONS = ['confirm', 'edit', 'cancel'];

    /** Suggested button titles (Meta: 25 characters at most), in BUTTONS order. */
    public const BUTTON_TITLES = ['confirm' => 'تأكيد الأوردر', 'edit' => 'تعديل', 'cancel' => 'إلغاء'];

    /** One example value per BODY variable, for the template submission. */
    public const EXAMPLES = [
        'first_name' => 'ليلى',
        'order_number' => '#1234',
        'items' => 'فستان سهرة ×2، طرحة',
        'total' => '1,250 ج.م',
        'address' => 'القاهرة - مدينة نصر، 12 شارع مكرم عبيد',
        'payment' => self::COD,
    ];

    public const COD = 'الدفع عند الاستلام';

    public const PAID = 'اتأكدنا من الدفع';

    public const ITEMS_MAX = 60;

    /** How much of the street address follows the governorate. */
    public const ADDRESS_LINE_MAX = 30;

    /** When the order carries no address at all (a template variable can never be empty). */
    public const NO_ADDRESS = 'العنوان المسجل في الأوردر';

    /** @return array<string, string> keyed by BODY name, in BODY order */
    public static function forOrder(Order $order): array
    {
        $values = [
            'first_name' => self::firstName($order),
            'order_number' => self::orderNumber($order),
            'items' => self::itemsSummary($order),
            'total' => self::total($order),
            'address' => self::address($order),
            'payment' => self::isPaid($order) ? self::PAID : self::COD,
        ];

        // The Cloud API refuses a parameter with a newline, a tab or a long run of spaces, and an empty one.
        return array_map(fn (string $v) => trim(preg_replace('/\s+/u', ' ', $v) ?? '') ?: '-', $values);
    }

    /** The readable text staff see for the template in the conversation thread. */
    public static function preview(string $kind, array $params): string
    {
        $title = $kind === 'reminder' ? 'تذكير بتأكيد الأوردر' : 'تأكيد الأوردر';

        return implode("\n", [
            $title.' '.$params['order_number'].' ('.$params['first_name'].')',
            $params['items'],
            'الإجمالي: '.$params['total'].' — '.$params['payment'],
            'العنوان: '.$params['address'],
            '['.implode('] [', self::BUTTON_TITLES).']',
        ]);
    }

    public static function isPaid(Order $order): bool
    {
        return $order->financial_status === 'paid';
    }

    private static function firstName(Order $order): string
    {
        $name = trim((string) ($order->shipping_name ?: $order->customer?->name));
        $first = $name === '' ? '' : (preg_split('/\s+/u', $name)[0] ?? '');

        return $first !== '' ? $first : 'حضرتك';
    }

    private static function orderNumber(Order $order): string
    {
        if (filled($order->shopify_order_name)) {
            return (string) $order->shopify_order_name;
        }

        return '#'.($order->order_number ?: $order->id);
    }

    private static function itemsSummary(Order $order): string
    {
        $summary = $order->items
            ->map(fn (OrderItem $i) => trim((string) $i->title).((int) $i->qty > 1 ? ' ×'.(int) $i->qty : ''))
            ->filter(fn (string $line) => $line !== '')
            ->implode('، ');

        if ($summary === '') {
            return 'طلبك';
        }

        return mb_strlen($summary) > self::ITEMS_MAX ? rtrim(mb_substr($summary, 0, self::ITEMS_MAX - 1)).'…' : $summary;
    }

    /** Governorate, then the start of the street address, so she can spot a wrong one. */
    private static function address(Order $order): string
    {
        $squash = fn (?string $v) => trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
        $governorate = $squash($order->shipping_province) ?: $squash($order->shipping_city);
        $line = $squash($order->shipping_address);

        if ($line !== '' && mb_strlen($line) > self::ADDRESS_LINE_MAX) {
            $line = rtrim(mb_substr($line, 0, self::ADDRESS_LINE_MAX - 1)).'…';
        }

        $parts = array_values(array_filter([$governorate, $line], fn (string $p) => $p !== ''));

        return $parts === [] ? self::NO_ADDRESS : implode(' - ', $parts);
    }

    private static function total(Order $order): string
    {
        $total = (float) $order->total;
        $amount = number_format($total, fmod($total, 1.0) === 0.0 ? 0 : 2);
        $currency = strtoupper((string) ($order->currency ?: 'EGP'));

        return $amount.' '.($currency === 'EGP' ? 'ج.م' : $currency);
    }
}
