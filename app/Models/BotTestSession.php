<?php

namespace App\Models;

use App\Support\LocalizedNumbers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One tester's run of a test link (design 2026-09-21 §3). "Start over" ends
 * this row and opens the next one for the same tester, so every run keeps its
 * own conversation and its own line in the report.
 */
class BotTestSession extends Model
{
    public const ENDED_RESET = 'reset';

    public const ENDED_LINK_STOPPED = 'link_stopped';

    public const ENDED_CAP = 'cap_reached';

    protected $fillable = [
        'bot_test_link_id',
        'session_token',
        'tester_name',
        'run_no',
        'customer_id',
        'conversation_id',
        'device_family',
        'user_agent',
        'ip_hash',
        'started_at',
        'last_seen_at',
        'ended_at',
        'ended_reason',
    ];

    protected function casts(): array
    {
        return [
            'run_no' => 'integer',
            'messages_count' => 'integer',
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public static function newToken(): string
    {
        return Str::lower(Str::random(48));
    }

    public function isEnded(): bool
    {
        return $this->ended_at !== null;
    }

    /** «سارة — الجلسة 2»; the first run is just the name. Read-time, so it follows the viewer's locale. */
    public function label(): string
    {
        return $this->run_no > 1
            ? __('labels.test_session.run', ['name' => $this->tester_name, 'n' => LocalizedNumbers::integer((int) $this->run_no)])
            : $this->tester_name;
    }

    public function durationSeconds(): int
    {
        $from = $this->started_at ?? $this->created_at;
        $to = $this->ended_at ?? $this->last_seen_at ?? $this->created_at;

        if ($from === null || $to === null) {
            return 0;
        }

        return (int) max(0, $from->diffInSeconds($to, false));
    }

    /** @return BelongsTo<BotTestLink, $this> */
    public function link(): BelongsTo
    {
        return $this->belongsTo(BotTestLink::class, 'bot_test_link_id');
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<BotTestSessionStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(BotTestSessionStep::class);
    }
}
