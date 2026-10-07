<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One production load test (2026-10-07): the backlog it seeded, the plan of waves it runs
 * (`plan` = {every, count, hours, spread}; null while it only has a backlog) and its counters.
 * At most one is `active`; follow-ups only ever play for a chat of the active run.
 *
 * @property array{every:int, count:int, hours:int, spread:int}|null $plan
 */
class LoadTestRun extends Model
{
    public const ACTIVE = 'active';

    public const STOPPED = 'stopped';

    protected $fillable = [
        'status', 'plan', 'started_at', 'waves_until', 'next_wave_at', 'stopped_at',
        'waves_done', 'openers_sent', 'seeded', 'followups_sent',
    ];

    protected function casts(): array
    {
        return [
            'plan' => 'array',
            'started_at' => 'datetime',
            'waves_until' => 'datetime',
            'next_wave_at' => 'datetime',
            'stopped_at' => 'datetime',
            'waves_done' => 'integer',
            'openers_sent' => 'integer',
            'seeded' => 'integer',
            'followups_sent' => 'integer',
        ];
    }

    public static function active(): ?self
    {
        return self::query()->where('status', self::ACTIVE)->latest('id')->first();
    }

    /** The active run, or a new one (no plan yet). */
    public static function activeOrStart(): self
    {
        return self::active() ?? self::create(['status' => self::ACTIVE, 'started_at' => now()]);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
