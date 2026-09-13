<?php

namespace App\Models;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerIdentity extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerIdentityFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'platform',
        'external_id',
        'username',
        'display_name',
        'avatar_url',
        'spam_allowlisted',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'spam_allowlisted' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
