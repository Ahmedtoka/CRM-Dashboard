<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdSpendSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = ['ad_account_id', 'date', 'hour', 'spend', 'captured_at'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'hour' => 'integer', 'spend' => 'decimal:2', 'captured_at' => 'datetime'];
    }
}
