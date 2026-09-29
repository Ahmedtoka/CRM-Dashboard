<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a moderator's attendance: `in | break | back | out | auto_out` (attendance design
 * §4). `business_date` is her shift's date, kept as the plain 'Y-m-d' string (no date cast), so
 * it is written and compared the same way on MariaDB and SQLite. `by_user_id` is who did it for
 * her; null when she did it herself or the system did.
 */
class QueueAttendanceEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_user_id');
    }
}
