<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BotRule extends Model
{
    /** @use HasFactory<\Database\Factories\BotRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'is_active',
        'priority',
        'scope',
        'platforms',
        'match_type',
        'keywords',
        'public_replies',
        'private_reply',
        'action',
        'hits',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority' => 'integer',
            'platforms' => 'array',
            'keywords' => 'array',
            'public_replies' => 'array',
            'hits' => 'integer',
        ];
    }
}
