<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One nightly learning review of a Cairo day (design §6): the Arabic summary
 * the owner reads, the day's stats and the token cost of the single Claude
 * call. Its suggestions are applied only after the owner approves them.
 */
class BotLearningReport extends Model
{
    protected $fillable = [
        'report_date',
        'summary',
        'stats',
        'model',
        'input_tokens',
        'output_tokens',
    ];

    protected function casts(): array
    {
        return [
            // `date:Y-m-d` so the page (and the tests) see a plain day, not a timestamp.
            'report_date' => 'date:Y-m-d',
            'stats' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }

    /** @return HasMany<BotSuggestion, $this> */
    public function suggestions(): HasMany
    {
        return $this->hasMany(BotSuggestion::class, 'report_id');
    }
}
