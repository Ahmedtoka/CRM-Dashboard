<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The last known status of one ads:health check, and which status the admins were last told about. Not ads history. */
class AdsHealthState extends Model
{
    protected $table = 'ads_health_state';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['since' => 'datetime', 'notified_at' => 'datetime', 'detail' => 'array'];
    }
}
