<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** fired | escalated | notified | seen | snoozed | unsnoozed | dismissed | resolved | acted */
class AdsAlertEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ads_alert_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(AdsAlert::class, 'ads_alert_id');
    }
}
