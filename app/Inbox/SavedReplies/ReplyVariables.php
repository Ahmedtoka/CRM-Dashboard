<?php

namespace App\Inbox\SavedReplies;

use App\Commerce\ShippingQuote;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Shopify\Connection\IntegrationRepository;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Substitutes `{variable}` placeholders in a saved reply's body (spec §2.2).
 * Every variable has an English canonical key and an Arabic alias — either
 * spelling may appear in a reply's body, and both resolve to the same value.
 * Nothing is sent automatically: this only produces text plus the list of
 * variables that resolved empty.
 */
final class ReplyVariables
{
    public const ALIASES = [
        'customer_name' => 'اسم_العميلة', 'customer_first_name' => 'الاسم_الأول', 'order_number' => 'رقم_الأوردر',
        'order_status' => 'حالة_الأوردر', 'shipping_fee' => 'سعر_الشحن', 'tracking_number' => 'رقم_الشحنة',
        'agent_name' => 'اسم_الموظف', 'store_name' => 'اسم_المتجر',
    ];

    public function __construct(
        private readonly ShippingQuote $quotes,
        private readonly OrderStatusLabel $labels,
        private readonly IntegrationRepository $integrations,
    ) {}

    public function render(string $body, Conversation $c, User $agent): RenderedReply
    {
        $customer = $c->customer;
        $orderLoaded = false;
        $order = null;
        $latestOrder = function () use ($c, &$orderLoaded, &$order): ?Order {
            if (! $orderLoaded) {
                $orderLoaded = true;
                $order = $this->latestOpenOrder((int) $c->customer_id);
            }

            return $order;
        };

        return $this->substitute($body, fn (string $key): ?string => match ($key) {
            'customer_name' => $customer?->name,
            'customer_first_name' => $customer?->name ? Str::before(trim($customer->name), ' ') : null,
            'order_number' => ($o = $latestOrder()) ? ($o->shopify_order_name ?: $o->order_number ?: '#'.$o->id) : null,
            'order_status' => ($o = $latestOrder()) ? $this->labels->for($o) : null,
            'shipping_fee' => $this->shippingFee($customer),
            'tracking_number' => ($o = $latestOrder())
                ? ($o->shipment?->tracking_number ?: $o->fulfillments()->whereNotNull('tracking_number')->latest('id')->value('tracking_number'))
                : null,
            'agent_name' => $agent->name,
            'store_name' => $this->storeName(),
            default => null,
        });
    }

    public function renderSample(string $body, User $agent): RenderedReply
    {
        $sample = [
            'customer_name' => 'منى أحمد', 'customer_first_name' => 'منى', 'order_number' => '#1024', 'order_status' => 'في الطريق',
            'shipping_fee' => '60 EGP', 'tracking_number' => 'BST-558812', 'agent_name' => $agent->name, 'store_name' => $this->storeName(),
        ];

        return $this->substitute($body, fn (string $key) => $sample[$key]);
    }

    public function storeName(): string
    {
        return (string) ($this->integrations->current()?->shop_name ?: config('app.name'));
    }

    /** @param  Closure(string): ?string  $resolve */
    private function substitute(string $body, Closure $resolve): RenderedReply
    {
        $lookup = [];
        foreach (self::ALIASES as $key => $alias) {
            $lookup[$key] = $key;
            $lookup[$alias] = $key;
        }
        $values = [];
        $missing = [];
        $text = preg_replace_callback('/\{([^{}\s]+)\}/u', function (array $m) use ($lookup, $resolve, &$values, &$missing) {
            $key = $lookup[$m[1]] ?? null;
            if ($key === null) {
                return $m[0];
            }
            if (! array_key_exists($key, $values)) {
                $values[$key] = trim((string) ($resolve($key) ?? ''));
            }
            if ($values[$key] === '') {
                $missing[$key] = $key;
            }

            return $values[$key];
        }, $body) ?? $body;

        return new RenderedReply($text, array_values($missing));
    }

    private function latestOpenOrder(int $customerId): ?Order
    {
        return Order::query()->where('customer_id', $customerId)
            ->whereIn('status', [OrderStatus::Submitting->value, OrderStatus::AwaitingPayment->value, OrderStatus::Confirmed->value])
            ->whereNot(fn (Builder $q) => $q
                ->whereHas('shipment', fn (Builder $s) => $s->whereIn('status', [ShipmentStatus::Delivered->value, ShipmentStatus::Returned->value]))
                ->orWhereHas('fulfillments', fn (Builder $f) => $f->where('shipment_status', 'delivered')))
            ->latest('id')->first();
    }

    private function shippingFee(?Customer $customer): ?string
    {
        // Spec §2.2: "the customer's latest address" — latest by id, not the
        // default address (controller ruling: the spec wording wins).
        $code = $customer?->addresses()->whereNotNull('province_code')->latest('id')->value('province_code');
        if (! $code) {
            return null;
        }
        $option = $this->quotes->quote($code, '0')[0] ?? null;

        return $option ? rtrim(rtrim($option->price, '0'), '.').' EGP' : null;
    }
}
