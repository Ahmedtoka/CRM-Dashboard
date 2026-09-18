<?php

namespace App\Models;

use Database\Factories\QuickReplyCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuickReplyCategory extends Model
{
    /** @use HasFactory<QuickReplyCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'sort',
    ];

    /**
     * @return HasMany<QuickReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(QuickReply::class, 'category_id');
    }
}
