<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The TikTok `conversation_id` of one customer on one messaging account (x6, 2026-10-24): written by
 * every inbound webhook, read by TikTokAdapter when the CRM replies (TikTok sends to a conversation,
 * never to a user, and a business can never open one itself).
 */
class TikTokConversation extends Model
{
    protected $table = 'tiktok_conversations';

    protected $fillable = ['business_id', 'customer_external_id', 'conversation_id', 'customer_username', 'last_inbound_at'];

    protected function casts(): array
    {
        return ['last_inbound_at' => 'datetime'];
    }

    public static function remember(string $businessId, string $customerExternalId, string $conversationId, ?string $username, DateTimeInterface $at): self
    {
        $row = static::query()->firstOrNew(['business_id' => $businessId, 'customer_external_id' => $customerExternalId]);
        $row->conversation_id = $conversationId;

        if ($username !== null && $username !== '') {
            $row->customer_username = $username;
        }

        if ($row->last_inbound_at === null || $row->last_inbound_at->lt($at)) {
            $row->last_inbound_at = $at;
        }

        $row->save();

        return $row;
    }

    public static function idFor(string $businessId, string $customerExternalId): ?string
    {
        $id = static::query()->where('business_id', $businessId)->where('customer_external_id', $customerExternalId)->value('conversation_id');

        return $id === null ? null : (string) $id;
    }
}
