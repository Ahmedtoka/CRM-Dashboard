<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifySyncRun extends Model
{
    /** @use HasFactory<\Database\Factories\ShopifySyncRunFactory> */
    use HasFactory;

    protected $fillable = [
        'type',
        'resource',
        'range_from',
        'range_to',
        'status',
        'processed',
        'created',
        'updated',
        'skipped_stale',
        'failed',
        'errors',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'range_from' => 'datetime',
            'range_to' => 'datetime',
            'processed' => 'integer',
            'created' => 'integer',
            'updated' => 'integer',
            'skipped_stale' => 'integer',
            'failed' => 'integer',
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
