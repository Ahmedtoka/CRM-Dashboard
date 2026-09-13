<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    /** @use HasFactory<\Database\Factories\WebhookEventFactory> */
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
