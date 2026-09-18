<?php

namespace App\Bot\Flow\Orders;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * PLACEHOLDER CONTRACT until the owner shares the OMS API.
 *
 * Assumed: GET {crm.oms.base_url}/orders/{number} with `Authorization: Bearer {crm.oms.token}`,
 * answering JSON `{status, updated_at, courier, tracking_url}`. 404 = unknown order (null);
 * any other failure throws so OrderLookup falls back to Shopify data. Unknown status values
 * map to null (Shopify data is used instead).
 */
class HttpOmsClient implements OmsClient
{
    private const STATE_MAP = [
        'hold' => 'hold',
        'prepared' => 'prepared',
        'shipped' => 'shipped',
        'on the way' => 'on_the_way',
        'on_the_way' => 'on_the_way',
        'out_for_delivery' => 'on_the_way',
        'delivered' => 'delivered',
        'returned' => 'returned',
        'cancelled' => 'cancelled',
        'canceled' => 'cancelled',
    ];

    public function status(string $orderNumber): ?OmsStatus
    {
        $response = Http::withToken((string) config('crm.oms.token'))
            ->acceptJson()
            ->timeout((int) config('crm.oms.timeout', 8))
            ->get(rtrim((string) config('crm.oms.base_url'), '/').'/orders/'.rawurlencode(ltrim(trim($orderNumber), '#')));

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        $state = self::STATE_MAP[mb_strtolower(trim((string) $response->json('status')))] ?? null;

        if ($state === null) {
            return null;
        }

        return new OmsStatus(
            $state,
            $this->time($response->json('updated_at')),
            is_string($response->json('courier')) ? $response->json('courier') : null,
            is_string($response->json('tracking_url')) && $response->json('tracking_url') !== '' ? $response->json('tracking_url') : null,
        );
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
