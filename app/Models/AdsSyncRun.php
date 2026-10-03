<?php

namespace App\Models;

use Database\Factories\AdsSyncRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdsSyncRun extends Model
{
    /** @use HasFactory<AdsSyncRunFactory> */
    use HasFactory;

    protected $fillable = ['ad_account_id', 'platform', 'kind', 'status', 'from_date', 'to_date', 'ads_count', 'rows_count', 'error', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['from_date' => 'date:Y-m-d', 'to_date' => 'date:Y-m-d', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class, 'ad_account_id');
    }
}
