<?php

namespace App\Http\Resources;

use App\Http\Support\StoredMessage;
use App\Media\MediaUrls;
use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MessageAttachment */
class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type?->value,
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            'original_name' => $this->original_name,
            'width' => $this->width,
            'height' => $this->height,
            'duration_ms' => $this->duration_ms,
            'status' => $this->status?->value,
            'error' => StoredMessage::error($this->error),
            'url' => $this->isStored() ? MediaUrls::show($this->resource) : null,
            'thumb_url' => MediaUrls::thumb($this->resource),
        ];
    }
}
