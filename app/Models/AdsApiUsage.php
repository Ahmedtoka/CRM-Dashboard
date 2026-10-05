<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One Meta quota reading (telemetry, pruned after 35 days; not ads history). */
class AdsApiUsage extends Model
{
    protected $table = 'ads_api_usage';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'regain_minutes' => 'integer'];
    }
}
