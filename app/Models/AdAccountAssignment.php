<?php

namespace App\Models;

use Database\Factories\AdAccountAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdAccountAssignment extends Model
{
    /** @use HasFactory<AdAccountAssignmentFactory> */
    use HasFactory;

    protected $fillable = ['ad_account_id', 'media_buyer_id', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class, 'ad_account_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(MediaBuyer::class, 'media_buyer_id');
    }
}
