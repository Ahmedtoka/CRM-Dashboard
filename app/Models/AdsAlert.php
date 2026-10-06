<?php

namespace App\Models;

use Database\Factories\AdsAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One finding of a decision rule (control room S5). Lifecycle: open ⇄ snoozed → dismissed | resolved | acted. */
class AdsAlert extends Model
{
    /** @use HasFactory<AdsAlertFactory> */
    use HasFactory;

    public const OPEN = 'open';

    public const SNOOZED = 'snoozed';

    public const DISMISSED = 'dismissed';

    public const RESOLVED = 'resolved';

    public const ACTED = 'acted';

    public const LIVE_STATES = [self::OPEN, self::SNOOZED];

    public const CLOSED_STATES = [self::DISMISSED, self::RESOLVED, self::ACTED];

    protected $table = 'ads_alerts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'params' => 'array', 'evidence' => 'array', 'money_at_risk_per_day' => 'decimal:2', 'entity_id' => 'integer',
            'first_fired_at' => 'datetime', 'last_evaluated_at' => 'datetime', 'snoozed_until' => 'datetime',
            'cooldown_until' => 'datetime', 'closed_at' => 'datetime', 'seen_at' => 'datetime', 'notified_at' => 'datetime',
        ];
    }

    public function isLive(): bool
    {
        return in_array($this->state, self::LIVE_STATES, true);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class, 'ad_account_id');
    }

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(MediaBuyer::class, 'buyer_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function writeAction(): BelongsTo
    {
        return $this->belongsTo(AdWriteAction::class, 'write_action_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AdsAlertEvent::class, 'ads_alert_id');
    }
}
