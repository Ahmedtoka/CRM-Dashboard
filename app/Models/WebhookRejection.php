<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One provider's refused webhooks in one hour (App\Webhooks\WebhookRejections). */
class WebhookRejection extends Model
{
    protected $fillable = ['provider', 'hour', 'count', 'last_reason', 'last_path', 'last_at'];

    protected function casts(): array
    {
        return [
            'hour' => 'datetime',
            'count' => 'integer',
            'last_at' => 'datetime',
        ];
    }
}
