<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Stop / Run attempt on an ad platform (see AdWriteService). */
class AdAction extends Model
{
    public const OK = 'ok';

    public const ERROR = 'error';

    protected $fillable = ['user_id', 'platform', 'ad_account_id', 'level', 'external_id', 'name', 'from_status', 'to_status', 'reason', 'result', 'error'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class, 'ad_account_id');
    }
}
