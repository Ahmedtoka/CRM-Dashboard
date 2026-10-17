<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One provider's refused webhooks in one hour (App\Webhooks\WebhookRejections). */
class WebhookRejection extends Model
{
    protected $fillable = ['provider', 'hour', 'rejected', 'last_reason', 'last_path', 'last_at', 'notified'];

    protected function casts(): array
    {
        return [
            'hour' => 'datetime',
            'rejected' => 'integer',
            'notified' => 'boolean',
            'last_at' => 'datetime',
        ];
    }
}
