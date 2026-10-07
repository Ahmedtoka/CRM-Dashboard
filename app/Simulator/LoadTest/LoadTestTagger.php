<?php

namespace App\Simulator\LoadTest;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\ProductVariant;

/**
 * Marks the conversation a load-test opener opened (`conversations.meta.load_test`): which run
 * and scenario it plays, and how many follow-ups were sent. Only a load-test channel's message
 * is ever tagged, and an existing tag is never overwritten (a later message of the same chat
 * keeps its scenario and step).
 */
final class LoadTestTagger
{
    /**
     * @param  array<string, mixed>|null  $tag  {run, scenario, name, customer_key, seeded?}
     */
    public static function tag(?Message $message, mixed $tag): ?Message
    {
        if ($message === null || ! is_array($tag) || ! isset($tag['run'], $tag['scenario'])) {
            return $message;
        }

        $c = Conversation::query()->with('channelAccount')->find($message->conversation_id);

        if ($c === null || ! $c->channelAccount?->is_load_test || isset(($c->meta ?? [])['load_test'])) {
            return $message;
        }

        $c->forceFill(['meta' => array_merge($c->meta ?? [], ['load_test' => [
            'run' => (int) $tag['run'],
            'scenario' => (string) $tag['scenario'],
            'name' => (string) ($tag['name'] ?? ''),
            'customer_key' => (string) ($tag['customer_key'] ?? ''),
            'order_number' => isset($tag['order_number']) ? (string) $tag['order_number'] : null,
            'step' => 0,
            'pending' => null,
        ]])])->save();

        if (isset($tag['order_number'])) {
            self::fakeOrder($c, (string) $tag['order_number'], (string) ($tag['name'] ?? ''));
        }

        return $message;
    }

    /**
     * The order her lines talk about: a test order (`is_load_test`, invisible to every report, sync
     * and real lookup) with a fake number, a few days old and on its way, so the bot's and the
     * agent's lookups in her chat find it — never a real customer's order.
     */
    private static function fakeOrder(Conversation $c, string $number, string $name): void
    {
        $variant = ProductVariant::query()->whereNotNull('price')->inRandomOrder()->first();
        $price = (float) ($variant?->price ?? 950);
        $placed = now()->subDays(random_int(2, 6));

        $order = Order::withLoadTest()->create([
            'is_load_test' => true,
            'customer_id' => $c->customer_id,
            'conversation_id' => $c->id,
            'platform' => $c->platform,
            'type' => OrderType::Cod,
            'source' => OrderSource::Chat,
            'status' => OrderStatus::Confirmed,
            'financial_status' => 'pending',
            'fulfillment_status' => null,
            'order_number' => $number,
            'shopify_order_name' => '#'.$number,
            'subtotal' => $price,
            'shipping_fee' => 60,
            'discount' => 0,
            'total' => $price + 60,
            'currency' => (string) config('crm.currency', 'EGP'),
            'shipping_name' => $name,
            'shipping_city' => 'القاهرة',
            'shipping_province_code' => 'C',
            'shipping_address' => 'عنوان تيست',
            'placed_at' => $placed,
            'note' => 'أوردر تيست (load test)',
        ]);
        $order->forceFill(['created_at' => $placed])->save();

        if ($variant !== null) {
            $order->items()->create(['variant_id' => $variant->id, 'title' => (string) ($variant->title ?? 'منتج'), 'sku' => $variant->sku, 'qty' => 1, 'price' => $price]);
        }
    }
}
