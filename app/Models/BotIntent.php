<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of the bot's intent catalog (spec §2.3): how the flow routes an
 * intent (answer / lookup / collect_then_handover / handover), its priority
 * and queue, the `script.*` knowledge keys it answers with and the details
 * it must collect. Not to be confused with the legacy enum App\Enums\BotIntent.
 */
class BotIntent extends Model
{
    protected $fillable = [
        'key',
        'group',
        'label_ar',
        'label_en',
        'route',
        'flow_key',
        'priority',
        'queue',
        'script_keys',
        'required_details',
        'keywords',
        'is_active',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'script_keys' => 'array',
            'required_details' => 'array',
            'keywords' => 'array',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }
}
