<?php

namespace App\Models;

use Database\Factories\BotKnowledgeEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BotKnowledgeEntry extends Model
{
    /** @use HasFactory<BotKnowledgeEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'title',
        'body',
        'is_active',
        'is_template',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_template' => 'boolean',
            'sort' => 'integer',
        ];
    }
}
