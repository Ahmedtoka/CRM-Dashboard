<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One queue message job that created its Message (close-cycle hotfix C1 / H-04): its `send_key`
 * is claimed in the Message's own transaction, so a retried `SendQueueMessage` never sends twice.
 * Kept two weeks (`queue:tick` prunes).
 */
class QueueMessageSend extends Model
{
    public const UPDATED_AT = null;

    public const KEPT_DAYS = 14;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
