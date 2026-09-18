<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One guided flow definition (Task 3): a menu or step-by-step script the bot
 * engine (a later task) walks the customer through. `definition` holds the
 * `start` step key and the `steps` map validated by App\Bot\Flows\FlowDefinition.
 *
 * The flow designer (Task 1) layers drafts and version history on top via
 * `BotFlowVersion`: `versions()` is the full history, `draft()` the single
 * pending draft (if any) and `publishedVersion()` the version mirrored into
 * `definition` above. See App\Bot\Flows\FlowDrafts.
 */
class BotFlow extends Model
{
    protected $fillable = [
        'key',
        'title_ar',
        'is_active',
        'definition',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'definition' => 'array',
        ];
    }

    public static function active(string $key): ?self
    {
        return static::query()->where('key', $key)->where('is_active', true)->first();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BotFlowVersion::class);
    }

    public function draft(): HasOne
    {
        return $this->hasOne(BotFlowVersion::class)->where('status', 'draft');
    }

    public function publishedVersion(): HasOne
    {
        return $this->hasOne(BotFlowVersion::class)->where('status', 'published');
    }
}
