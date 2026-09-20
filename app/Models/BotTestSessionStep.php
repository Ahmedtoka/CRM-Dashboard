<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step a tester reached inside a guided flow (design 2026-09-21 §4). The
 * rows are written by App\TestLinks\TestSessionSteps from the single place the
 * bot changes a conversation's flow state, and the report's funnel counts the
 * distinct sessions that reached each step.
 */
class BotTestSessionStep extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'bot_test_session_id',
        'flow_key',
        'step_id',
        'entered_at',
    ];

    protected function casts(): array
    {
        return ['entered_at' => 'datetime'];
    }

    /** @return BelongsTo<BotTestSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(BotTestSession::class, 'bot_test_session_id');
    }
}
