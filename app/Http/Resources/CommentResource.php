<?php

namespace App\Http\Resources;

use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Comment */
class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $post = $this->post;
        $customer = $this->customer_id !== null ? $this->customer : null;
        $repliedBy = $this->replied_by_id !== null ? $this->repliedBy : null;

        return [
            'id' => $this->id,
            'post' => $post ? [
                'id' => $post->id,
                'platform' => $post->platform?->value,
                'caption' => $post->caption,
                'permalink' => $post->permalink,
                'thumbnail_url' => $post->thumbnail_url,
                'is_ad' => (bool) $post->is_ad,
                // The ad behind the post (2026-09-25), when known.
                'ad' => $post->is_ad ? array_filter([
                    'id' => $post->ad_id,
                    'title' => $post->ad_title,
                    'name' => $post->ad_name,
                    'adset' => $post->ad_adset_name,
                    'campaign' => $post->ad_campaign_name,
                ]) ?: null : null,
            ] : null,
            'customer' => $customer ? ['id' => $customer->id, 'name' => $customer->name, 'avatar_url' => $customer->avatar_url] : null,
            'parent_external_id' => $this->parent_external_id,
            'body' => $this->body,
            'status' => $this->status?->value,
            'intent' => $this->intent?->value,
            'public_reply' => $this->public_reply,
            'public_replied_at' => $this->public_replied_at?->toIso8601String(),
            'replied_by_type' => $this->replied_by_type?->value,
            'replied_by_id' => $this->replied_by_id,
            'replied_by' => $repliedBy ? ['id' => $repliedBy->id, 'name' => $repliedBy->name] : null,
            'private_reply_sent_at' => $this->private_reply_sent_at?->toIso8601String(),
            'conversation_id' => $this->conversation_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
