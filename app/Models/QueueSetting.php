<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QueueSetting extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public const DEFAULT_POINTS = [
        'inquiry' => 8, 'problem' => 12, 'case_open' => 0, 'case_resolved_in_time' => 14, 'case_resolved_late' => 4,
        'speed_fast' => 6, 'speed_ok' => 3, 'review_per_star' => 5, 'qa_per_point' => 5, 'auto_close' => 0, 'escalation' => 0, 'daily_cap' => 350,
    ];

    public const DEFAULT_SHIFTS = [
        ['key' => 'morning', 'name' => 'صباحي', 'from' => '10:00', 'to' => '18:00', 'location' => 'office', 'leader_user_id' => null],
        ['key' => 'evening', 'name' => 'مسائي', 'from' => '18:00', 'to' => '00:00', 'location' => 'home', 'leader_user_id' => null],
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'night_message_enabled' => 'boolean',
            'points' => 'array', 'shifts' => 'array', 'default_roster' => 'array',
        ];
    }

    /**
     * The settings row: one query once it exists. Callers that loop (the tick, the router, the
     * board snapshot) read it once and pass it down.
     */
    public static function current(): self
    {
        // firstOrCreate() only hydrates attributes it was given; a freshly-created
        // row's DB-default columns (enabled, windows_per_moderator, ...) would
        // otherwise read back as null in memory instead of their real values.
        return static::query()->find(1)
            ?? static::firstOrCreate(['id' => 1], ['points' => self::DEFAULT_POINTS, 'shifts' => self::DEFAULT_SHIFTS, 'default_roster' => []])->fresh();
    }

    public function point(string $key): int
    {
        return (int) (($this->points ?? [])[$key] ?? self::DEFAULT_POINTS[$key] ?? 0);
    }

    /** @return list<array{key:string,name:string,from:string,to:string,location:string,leader_user_id:?int}> */
    public function shiftTemplates(): array
    {
        $shifts = $this->shifts ?: self::DEFAULT_SHIFTS;

        return array_values(array_map(fn ($s) => $s + ['location' => 'office', 'leader_user_id' => null], $shifts));
    }
}
