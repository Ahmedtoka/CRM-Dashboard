<?php

namespace Database\Factories;

use App\Enums\AttachmentStatus;
use App\Enums\AttachmentType;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MessageAttachment>
 */
class MessageAttachmentFactory extends Factory
{
    protected $model = MessageAttachment::class;

    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'uploaded_by' => null,
            'type' => AttachmentType::Image,
            'disk' => 'media',
            'mime' => 'image/png',
            'status' => AttachmentStatus::Pending,
            'remote_url' => 'fixture:image',
        ];
    }

    public function stored(): static
    {
        return $this->state(fn () => [
            'status' => AttachmentStatus::Stored,
            'path' => 'outbound/2026/09/'.Str::uuid().'.png',
            'size_bytes' => 68,
            'width' => 1,
            'height' => 1,
            'remote_url' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => AttachmentStatus::Failed,
            'error' => 'download_http_404',
        ]);
    }
}
