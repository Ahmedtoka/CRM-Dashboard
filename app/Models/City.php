<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    /** @use HasFactory<\Database\Factories\CityFactory> */
    use HasFactory;

    protected $fillable = [
        'name_ar',
        'name_en',
        'shipping_fee',
    ];

    protected function casts(): array
    {
        return [
            'shipping_fee' => 'decimal:2',
        ];
    }
}
