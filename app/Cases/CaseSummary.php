<?php

namespace App\Cases;

use App\Bot\Flow\Orders\OrderStatusText;
use App\Bot\Flows\FlowDefinitions;
use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\ReturnFlowUpgrade;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupportCase;
use App\Support\LocalizedNumbers;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The organised summary of a case (owner-approved format), used by the
 * conversation note, the stored `summary` column, the case card and the cases
 * page: a header line, then sections for the customer, the order, the request,
 * attachments, alerts and what the team should do. A section with no content is
 * left out, except the alerts one, which says "none" and is flagged `empty`.
 *
 * The staff-facing parts (`header()`, `sections()`) are built on read, so they
 * come out in the viewer's language. The bot's own records — the stored
 * summary, the conversation notes and the notification excerpt — are written
 * once at record time and read later as history, so they stay Arabic whatever
 * the request locale is (see `asRecorded()`).
 */
final class CaseSummary
{
    private const MAX_ITEMS = 5;

    /** Shopify's placeholder title for a product that has no options. */
    private const DEFAULT_VARIANT_TITLES = ['default title', 'default'];

    public static function header(SupportCase $case): string
    {
        $priority = in_array($case->priority, ['high', 'medium'], true) ? $case->priority : 'medium';

        return __('cases.header', [
            'id' => (string) $case->id,
            'type' => $case->typeLabel(),
            'priority' => __('cases.priority.'.$priority),
        ]);
    }

    /** @return list<array{key:string, icon:string, title:string, lines:list<string>, empty?:bool}> */
    public static function sections(SupportCase $case): array
    {
        $data = is_array($case->data) ? $case->data : [];
        $alerts = self::policyNotes($case);

        return array_values(array_filter([
            self::customer($case, $data),
            self::order($case, $data),
            self::section('items', '🛍️', __('cases.sections.items'), self::selectedItemLines($data)),
            self::section('request', '📝', __('cases.sections.request'), self::requestLines($case, $data)),
            self::section('attachments', '📎', __('cases.sections.attachments'), self::attachmentLines($case, $data)),
            self::section('alerts', '⚠️', __('cases.sections.alerts'), $alerts !== [] ? $alerts : [__('cases.no_alerts')], $alerts === []),
            self::section('team_action', '➡️', __('cases.sections.team_action'), [self::teamAction($case, $data)]),
        ]));
    }

    /**
     * The stored alerts in the viewer's language. A note is either a code the
     * recorder stored (`['code' => …, 'params' => […]]`) or, for notes written
     * before that change and for the return-policy checker's own notes, the
     * finished Arabic sentence.
     *
     * @return list<string>
     */
    public static function policyNotes(SupportCase $case): array
    {
        $notes = [];

        foreach ((array) $case->policy_notes as $note) {
            if (is_array($note) && filled($note['code'] ?? null)) {
                $notes[] = (string) __('cases.policy.'.$note['code'], self::params($note['params'] ?? null));

                continue;
            }

            if (is_scalar($note) && filled((string) $note)) {
                $notes[] = (string) $note;
            }
        }

        return $notes;
    }

    /**
     * A stored note's parameters, with its numbers written in the viewer's script.
     *
     * @return array<string, string>
     */
    private static function params(mixed $params): array
    {
        $out = [];

        foreach (is_array($params) ? $params : [] as $key => $value) {
            $out[(string) $key] = is_int($value) || (is_string($value) && ctype_digit($value))
                ? LocalizedNumbers::integer((int) $value)
                : (is_scalar($value) ? (string) $value : '');
        }

        return $out;
    }

    /** The full note text: header and sections, blank-line separated. */
    public static function text(SupportCase $case): string
    {
        return self::asRecorded(function () use ($case) {
            $blocks = [self::header($case)];

            foreach (self::sections($case) as $s) {
                $blocks[] = implode("\n", array_merge([$s['icon'].' '.$s['title']], $s['lines']));
            }

            return implode("\n\n", $blocks);
        });
    }

    /** One line for a notification: the header and the first request line. */
    public static function excerpt(SupportCase $case): string
    {
        return self::asRecorded(function () use ($case) {
            foreach (self::sections($case) as $s) {
                if ($s['key'] === 'request' && $s['lines'] !== []) {
                    return self::header($case).' · '.$s['lines'][0];
                }
            }

            return self::header($case);
        });
    }

    /**
     * Builds a record the bot writes once and the team reads later as history,
     * so it keeps the Arabic it was written in no matter who is looking.
     */
    private static function asRecorded(callable $build): mixed
    {
        $locale = app()->getLocale();
        app()->setLocale('ar');

        try {
            return $build();
        } finally {
            app()->setLocale($locale);
        }
    }

    /** @return array{key:string, icon:string, title:string, lines:list<string>, empty?:bool}|null */
    private static function section(string $key, string $icon, string $title, array $lines, bool $empty = false): ?array
    {
        if ($lines === []) {
            return null;
        }

        $section = ['key' => $key, 'icon' => $icon, 'title' => $title, 'lines' => $lines];

        if ($empty) {
            $section['empty'] = true;
        }

        return $section;
    }

    private static function customer(SupportCase $case, array $data): array
    {
        $customer = $case->customer_id !== null ? $case->customer : null;
        $name = self::str($data['name'] ?? null) ?? self::str($customer?->name) ?? __('cases.customer.unknown');
        $phone = self::str($data['phone'] ?? null) ?? self::str($customer?->phone) ?? self::str($customer?->normalized_phone);

        return ['key' => 'customer', 'icon' => '👤', 'title' => __('cases.sections.customer'), 'lines' => [$phone !== null ? "{$name} · {$phone}" : $name]];
    }

    private static function order(SupportCase $case, array $data): ?array
    {
        $order = $case->order_id !== null ? $case->order : null;
        $number = self::str($order?->shopify_order_name) ?? self::str($order?->order_number) ?? self::str($case->order_number) ?? self::str($data['order_number'] ?? null);

        if ($order === null && $number === null) {
            $typed = self::str($data['order_ref_text'] ?? null);

            return $typed === null ? null : ['key' => 'order', 'icon' => '📦', 'title' => __('cases.order.not_found', ['ref' => $typed]), 'lines' => []];
        }

        $details = array_values(array_filter([
            ($date = self::placedAt($order, $data)) !== null ? __('cases.order.placed_on', ['date' => LocalizedNumbers::digits($date->setTimezone(OrderStatusText::TIMEZONE)->format('j/n'))]) : null,
            self::statusLabel($order, $data),
            $order !== null && $order->total !== null ? self::price((float) $order->total) : null,
        ]));
        $lines = $details === [] ? [] : [implode(' · ', $details)];

        if ($order !== null && ($items = self::itemsLine($order)) !== null) {
            $lines[] = $items;
        }

        $title = $number !== null ? __('cases.order.numbered', ['number' => ltrim($number, '#')]) : __('cases.sections.order');

        return ['key' => 'order', 'icon' => '📦', 'title' => $title, 'lines' => $lines];
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
            .' × '.LocalizedNumbers::integer($r['qty'])
            .($r['price'] !== null ? ' — '.self::price($r['price']) : '')
            .($r['exchange_only'] ? ' '.__('cases.items.exchange_only') : ''), self::selectedItems($data));
    }

    /** @return list<string> */
    private static function requestLines(SupportCase $case, array $data): array
    {
        $flow = $case->type;
        $lines = match ($case->type) {
            'return' => [
                self::labelled(__('cases.fields.kind'), __('cases.types.return')),
                self::labelled(__('cases.fields.reason'), self::optionTitle($flow, 'reason', $data)),
            ],
            'exchange' => [
                self::labelled(__('cases.fields.kind'), __('cases.types.exchange')),
                self::labelled(__('cases.fields.reason'), self::optionTitle($flow, 'reason', $data)),
                ...self::exchangeLines($data),
            ],
            'return_exchange' => [
                self::labelled(__('cases.fields.reason'), self::optionTitle($flow, 'reason', $data)),
                self::labelled(__('cases.fields.request'), self::optionTitle($flow, 'request', $data)),
            ],
            'complaint' => [
                self::labelled(__('cases.fields.complaint_type'), self::optionTitle($flow, 'complaint_type', $data)),
                self::labelled(__('cases.fields.branch'), self::str($data['branch_name'] ?? null)),
                self::labelled(__('cases.fields.visit_date'), self::optionTitle($flow, 'visit_date', $data)),
                self::labelled(__('cases.fields.description'), self::str($data['description'] ?? null)),
            ],
            'cancel_edit' => [
                self::labelled(__('cases.fields.request'), self::optionTitle($flow, 'request', $data)),
                self::labelled(__('cases.fields.cancel_reason'), self::str($data['cancel_reason'] ?? null)),
                self::labelled(__('cases.fields.edit_kind'), self::optionTitle($flow, 'edit_kind', $data)),
                ...FlowPrompter::changeLines($data['item_changes'] ?? []),
                self::labelled(__('cases.fields.new_address'), self::str($data['new_address'] ?? null)),
                self::labelled(__('cases.fields.new_phone'), self::str($data['new_phone'] ?? null)),
                self::labelled(__('cases.fields.edit_details'), self::str($data['edit_details'] ?? null)),
            ],
            'delivery_followup' => [self::labelled(__('cases.fields.delivery_status'), self::statusLabel($case->order_id !== null ? $case->order : null, $data))],
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
        return self::asRecorded(function () use ($data) {
            $items = FlowPrompter::itemsText($data['selected_items'] ?? []);
            $items = $items !== '' ? $items : __('cases.exchange.note_item');
            $product = self::exchangeProduct($data);

            if ($product !== null) {
                $parts = [$product['title'].($product['variant_title'] !== null ? " ({$product['variant_title']})" : '')];

                if ($product['price'] !== null) {
                    $parts[] = self::price($product['price']);
                }

                if ($product['url'] !== null) {
                    $parts[] = $product['url'];
                }

                return __('cases.exchange.note', ['items' => $items, 'product' => implode(' — ', $parts)]);
            }

            $typed = self::str($data['exchange_product_text'] ?? null);

            if ($typed !== null) {
                return __('cases.exchange.note_typed', ['items' => $items, 'text' => $typed]);
            }

            return ! empty($data['exchange_product_photo'])
                ? __('cases.exchange.note_photo', ['items' => $items])
                : __('cases.exchange.note_unknown', ['items' => $items]);
        });
    }

    /**
     * The internal note of an edit request (the owner's cancel/edit flow, 2026-09-19): every change
     * the team makes on the order. Null for a cancel request, or when nothing was changed.
     */
    public static function editNote(array $data): ?string
    {
        return self::asRecorded(function () use ($data) {
            if (($data['request'] ?? null) !== 'edit') {
                return null;
            }

            $lines = array_values(array_filter([
                ...FlowPrompter::changeLines($data['item_changes'] ?? []),
                ($address = self::str($data['new_address'] ?? null)) !== null ? __('cases.edit.new_address', ['address' => $address]) : null,
                ($phone = self::str($data['new_phone'] ?? null)) !== null ? __('cases.edit.new_phone', ['phone' => $phone]) : null,
            ]));

            if ($lines === []) {
                return null;
            }

            $number = self::str($data['order_number'] ?? null);
            $order = $number !== null
                ? __('cases.edit.note_order_numbered', ['number' => ltrim($number, '#')])
                : __('cases.edit.note_order_any');

            return __('cases.edit.note_header', ['order' => $order]).":\n".implode("\n", $lines);
        });
    }

    /** @return list<string> */
    private static function exchangeLines(array $data): array
    {
        $product = self::exchangeProduct($data);

        if ($product !== null) {
            return array_values(array_filter([
                __('cases.exchange.replacement').': '.$product['title']
                    .($product['variant_title'] !== null ? ' — '.$product['variant_title'] : '')
                    .($product['price'] !== null ? ' — '.self::price($product['price']) : ''),
                $product['url'] !== null ? __('cases.exchange.link').': '.$product['url'] : null,
            ]));
        }

        $typed = self::str($data['exchange_product_text'] ?? null);

        return [match (true) {
            $typed !== null => __('cases.exchange.typed', ['text' => $typed]),
            ! empty($data['exchange_product_photo']) => __('cases.exchange.photo'),
            default => __('cases.exchange.unknown'),
        }];
    }

    /** @return list<string> */
    private static function attachmentLines(SupportCase $case, array $data): array
    {
        if ($case->type === 'return') {
            return [__('cases.attachments.item_photo').' '.(! empty($data['product_photo']) ? '✅' : __('cases.attachments.no_photo'))];
        }

        if ($case->type === 'exchange') {
            return ! empty($data['exchange_product_photo']) ? [__('cases.attachments.replacement_photo').' ✅'] : [];
        }

        if ($case->type === 'complaint') {
            return ! empty($data['description_photo']) ? [__('cases.attachments.customer_photos').' ✅'] : [];
        }

        if ($case->type === 'cancel_edit') {
            return collect((array) ($data['item_changes'] ?? []))->contains(fn ($c) => is_array($c) && ! empty($c['photo'])) ? [__('cases.attachments.change_photo').' ✅'] : [];
        }

        if ($case->type !== 'return_exchange') {
            return [];
        }

        $fields = ['product_photo' => __('cases.attachments.product_photo')];

        if (($data['reason'] ?? null) === 'defective') {
            $fields['defect_photo'] = __('cases.attachments.defect_photo');
        }

        $parts = [];

        foreach ($fields as $field => $label) {
            $parts[] = $label.' '.(! empty($data[$field]) ? '✅' : '—');
        }

        return [implode(' · ', $parts)];
    }

    private static function teamAction(SupportCase $case, array $data): string
    {
        $request = is_scalar($data['request'] ?? null) ? (string) $data['request'] : '';
        $key = match ($case->type) {
            'return', 'exchange', 'complaint', 'delivery_followup' => 'cases.team_action.'.$case->type,
            'return_exchange' => 'cases.team_action.return_exchange.'.(in_array($request, ['refund', 'exchange'], true) ? $request : 'default'),
            'cancel_edit' => 'cases.team_action.cancel_edit.'.(isset($data['order_editable']) ? 'editable' : 'window').'.'.(in_array($request, ['cancel', 'edit'], true) ? $request : 'default'),
            default => 'cases.team_action.default',
        };

        return __($key);
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

            return "{$item->title}{$suffix} × ".LocalizedNumbers::integer((int) $item->qty);
        })->all();

        $rest = $items->count() - self::MAX_ITEMS;

        if ($rest > 0) {
            $shown[] = $rest === 1 ? __('cases.order.more_one') : __('cases.order.more_many', ['count' => LocalizedNumbers::integer($rest)]);
        }

        return __('cases.order.products', ['list' => implode(__('cases.order.separator'), $shown)]);
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

    /** An amount with the currency word, e.g. "1,250 ج.م". */
    private static function price(float $amount): string
    {
        return LocalizedNumbers::amount($amount).' '.__('cases.currency');
    }

    private static function str(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
