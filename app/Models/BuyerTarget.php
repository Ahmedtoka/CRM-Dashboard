<?php

namespace App\Models;

use Database\Factories\BuyerTargetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuyerTarget extends Model
{
    /** @use HasFactory<BuyerTargetFactory> */
    use HasFactory;

    protected $fillable = ['media_buyer_id', 'month', 'budget', 'target_roas'];

    protected function casts(): array
    {
        return ['month' => 'date:Y-m-d', 'budget' => 'decimal:2', 'target_roas' => 'decimal:2'];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(MediaBuyer::class, 'media_buyer_id');
    }
}
