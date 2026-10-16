<?php

namespace App\Shopify\Sync\Mappers;

use App\Commerce\Data\OrderPayload;
use App\Enums\OrderSource;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Whether a Shopify order payload's `crm_order_id` note attribute may be trusted
 * (audit A1). That attribute rides on a public cart/checkout, so any shopper can
 * set it; on its own it would let a storefront payment confirm (or a storefront
 * cancel cancel) an unrelated unpaid chat order. It is only trusted when the
 * payload also carries something a shopper cannot set:
 *  - the payload is the Shopify order already linked to the candidate, or
 *  - its draft_order_id is the candidate's own draft, or
 *  - its tags carry the CRM's per-order tag (OrderPayload::tagFor), which the
 *    CRM puts on every order/draft it creates and Shopify keeps on the order a
 *    draft converts into (order tags are admin/app-only).
 */
final class CrmOrderClaim
{
    public static function crmOrderId(array $o): ?int
    {
        $value = collect($o['note_attributes'] ?? [])
            ->first(fn ($attr) => ($attr['name'] ?? null) === 'crm_order_id')['value'] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** The candidate the claim names, when it is a chat order and the payload proves the link. */
    public static function trusts(?Order $candidate, array $o, ?string $shopifyId): bool
    {
        if ($candidate === null || $candidate->source !== OrderSource::Chat) {
            return false;
        }

        if ($candidate->shopify_order_id !== null) {
            return $candidate->shopify_order_id === $shopifyId;
        }

        $draftId = Payload::id($o['draft_order_id'] ?? $o['draft_order']['id'] ?? null);

        if ($draftId !== null && $candidate->shopify_draft_order_id !== null && $draftId === (string) $candidate->shopify_draft_order_id) {
            return true;
        }

        if (in_array(OrderPayload::tagFor($candidate->id), Payload::tags($o['tags'] ?? null), true)) {
            return true;
        }

        Log::warning('Shopify order payload carries an unproven crm_order_id; ignoring it.', [
            'crm_order_id' => $candidate->id,
            'shopify_order_id' => $shopifyId,
            'draft_order_id' => $draftId,
        ]);

        return false;
    }
}
