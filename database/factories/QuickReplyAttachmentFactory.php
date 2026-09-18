<?php

namespace Database\Factories;

use App\Enums\AttachmentType;
use App\Models\QuickReply;
use App\Models\QuickReplyAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QuickReplyAttachment>
 */
class QuickReplyAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quick_reply_id' => QuickReply::factory(),
            'disk' => 'media',
            'path' => 'replies/'.Str::uuid().'.png',
            'type' => AttachmentType::Image,
            'mime' => 'image/png',
            'size_bytes' => 68,
            'original_name' => 'chart.png',
            'sort' => 0,
        ];
    }
}
