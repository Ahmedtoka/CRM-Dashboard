<?php

namespace App\Models;

use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use HasFactory;

    protected $fillable = [
        'provider',
        'event_type',
        'dedupe_key',
        'received_at',
        'received_at_ms',
        'payload',
        'signature_valid',
        'status',
        'attempts',
        'error',
        'processed_at',
        // Shopify: which secret verified the delivery (App\Shopify\App\WebhookSignature).
        'verified_with',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'attempts' => 'integer',
            'received_at' => 'datetime',
            'received_at_ms' => 'integer',
            'processed_at' => 'datetime',
        ];
    }
}
