<?php

namespace App\Models;

use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One Le Voile store location (seeded from the brand website, Task 2): its
 * App\Bot\Flows\BranchFinder area key, address/contact and the alias phrases
 * that match a customer's free-text location to that area. Managed from
 * settings/Branches.
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    protected $fillable = [
        'governorate',
        'area_key',
        'area_ar',
        'area_en',
        'name',
        'address',
        'phone',
        'map_url',
        'hours',
        'aliases',
        'is_active',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }
}
