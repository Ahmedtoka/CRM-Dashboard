<?php

namespace App\Models;

use Database\Factories\AdDailyMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdDailyMetric extends Model
{
    /** @use HasFactory<AdDailyMetricFactory> */
    use HasFactory;

    protected $fillable = ['ad_id', 'ad_account_id', 'date', 'spend', 'impressions', 'clicks', 'reach', 'purchases', 'purchase_value'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'spend' => 'decimal:2', 'purchases' => 'decimal:2', 'purchase_value' => 'decimal:2', 'impressions' => 'integer', 'clicks' => 'integer', 'reach' => 'integer'];
    }

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class, 'ad_account_id');
    }
}
