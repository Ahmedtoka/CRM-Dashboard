<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMergeSuggestion extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerMergeSuggestionFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'candidate_id',
        'reason',
        'status',
        'resolved_by_id',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'candidate_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }
}
