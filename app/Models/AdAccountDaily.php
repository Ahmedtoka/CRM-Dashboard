<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One account-day of platform control totals (all statuses). Written by AdsSyncService::upsertAccountDaily only. */
class AdAccountDaily extends Model
{
    protected $table = 'ad_account_daily';

    public $timestamps = false;

    protected $fillable = [
        'ad_account_id', 'date', 'spend', 'impressions', 'purchases', 'purchase_value', 'currency',
        'first_spend', 'first_purchases', 'first_purchase_value', 'first_fetched_at', 'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d', 'spend' => 'decimal:2', 'impressions' => 'integer', 'purchases' => 'decimal:2',
            'purchase_value' => 'decimal:2', 'first_spend' => 'decimal:2', 'first_purchases' => 'decimal:2',
            'first_purchase_value' => 'decimal:2', 'first_fetched_at' => 'datetime', 'fetched_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class, 'ad_account_id');
    }
}
