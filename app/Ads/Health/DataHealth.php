<?php

namespace App\Ads\Health;

use App\Ads\AdsSettings;
use App\Ads\Platforms\Meta\UsageRecorder;
use App\Ads\Sync\SyncAdAccount;
use App\Inbox\UserNotifier;
use App\Mail\AdsSystemAlert;
use App\Models\AdAccount;
use App\Models\AdAccountDaily;
use App\Models\AdDailyMetric;
use App\Models\AdsHealthState;
use App\Models\AdsSyncRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Is the ads data trustworthy right now? evaluate() reads the facts (no writes); run() keeps ads_health_state in step and
 * tells the admins when a status has held for the hold-down time (a notice when it goes bad, one when it recovers). The
 * email goes only for a dead token, a sync silent for 6 h and a dead scheduler.
 */
class DataHealth
{
    /** The scheduler beat is written every minute: older than this means the cron job stopped. */
    private const SCHEDULER_STALE_MINUTES = 5;

    private const QUEUE_STALE_MINUTES = 15;

    public const STARTED_KEY = 'health_started_at';

    public function __construct(private readonly AdsSettings $settings, private readonly UsageRecorder $usage, private readonly UserNotifier $notifier) {}

    /** @return list<HealthCheck> */
    public function evaluate(): array
    {
        $accounts = AdAccount::query()->with('connection')->where('platform', 'meta')->where('is_active', true)->orderBy('id')->get();

        return [
            ...$this->accountChecks($accounts),
            $this->stuckRuns(),
            $this->scheduler(),
            ...$this->queues(),
            $this->assignmentsOverlap(),
            $this->linkRate(),
        ];
    }

    /**
     * stale, reconnect, read_only, control_gap and usage for each given account (Meta accounts with a connection).
     *
     * @param  Collection<int, AdAccount>  $accounts
     * @return list<HealthCheck>
     */
    public function accountChecks(Collection $accounts): array
    {
        $ids = $accounts->pluck('id')->all();
        $lastOk = AdsSyncRun::query()->where('status', 'ok')->whereIn('ad_account_id', $ids)->groupBy('ad_account_id')
            ->selectRaw('ad_account_id, MAX(finished_at) as at')->pluck('at', 'ad_account_id');

        // D-1 (the Cairo yesterday): today is still settling at the platform, so the control is compared on the last closed day.
        $day = CarbonImmutable::now('Africa/Cairo')->subDay()->toDateString();
        $control = AdAccountDaily::query()->whereIn('ad_account_id', $ids)->whereDate('date', $day)->pluck('spend', 'ad_account_id');
        $itemised = AdDailyMetric::query()->whereIn('ad_account_id', $ids)->whereDate('date', $day)->groupBy('ad_account_id')
            ->selectRaw('ad_account_id, SUM(spend) as spend')->pluck('spend', 'ad_account_id');

        $out = [];
        foreach ($accounts as $a) {
            $base = ['account_id' => $a->id, 'account' => $a->name];
            $c = $a->connection;

            $out[] = $this->stale($a, $lastOk[$a->id] ?? null, $base);
            $out[] = new HealthCheck("reconnect:{$a->id}", $c?->status === 'needs_reconnect' ? HealthCheck::CRITICAL : HealthCheck::OK, $base);
            $out[] = new HealthCheck("read_only:{$a->id}", $c?->read_only ? HealthCheck::WARN : HealthCheck::OK, $base);
            $out[] = $this->controlGap($a, (float) ($control[$a->id] ?? 0), (float) ($itemised[$a->id] ?? 0), $day, $base);
            $out[] = $this->usageCheck($a, $base);
        }

        return $out;
    }

    /** Evaluate, store, and notify on held transitions. @return list<HealthCheck> */
    public function run(): array
    {
        if ($this->settings->get(self::STARTED_KEY) === null) {
            $this->settings->set(self::STARTED_KEY, now()->toIso8601String());
        }

        $checks = $this->evaluate();
        foreach ($checks as $check) {
            $this->apply($check);
        }
        AdsHealthState::query()->whereNotIn('key', array_map(fn (HealthCheck $c) => $c->key, $checks))->delete();

        return $checks;
    }

    private function apply(HealthCheck $check): void
    {
        $state = AdsHealthState::query()->where('key', $check->key)->first();
        if ($state === null) {
            $state = AdsHealthState::query()->create([
                'key' => $check->key, 'status' => $check->status, 'since' => now(), 'detail' => $check->detail,
            ]);
        } elseif ($state->status !== $check->status) {
            $state->forceFill(['status' => $check->status, 'since' => now(), 'detail' => $check->detail])->save();
        } else {
            $state->forceFill(['detail' => $check->detail])->save();
        }

        // Informational: stored, never announced.
        if ($check->reason() === 'link_rate') {
            return;
        }

        $held = $state->since->lte(now()->subMinutes((int) config('crm.ads.health.hold_down_minutes', 30)));
        $told = $state->notified_status ?? HealthCheck::OK;
        if (! $held || $check->status === $told) {
            return;
        }

        // Compare-and-set on notified_status: two overlapping runs announce a transition once.
        $claimed = AdsHealthState::query()->where('id', $state->id)
            ->where(fn ($q) => $told === HealthCheck::OK
                ? $q->whereNull('notified_status')->orWhere('notified_status', HealthCheck::OK)
                : $q->where('notified_status', $told))
            ->update(['notified_status' => $check->status, 'notified_at' => now()]) === 1;
        if (! $claimed) {
            return;
        }

        $recovered = ! $check->isBad();
        $this->notifier->notifyAdmins('ads.data_health', [
            'key' => $check->key,
            'reason' => $check->reason(),
            'status' => $check->status,
            'account' => $check->detail['account'] ?? null,
            'account_id' => $check->accountId(),
            'subject' => $check->detail['queue'] ?? null,
            'recovered' => $recovered,
            'link' => '/ads/sync',
        ]);

        if (! $recovered && $this->emails($check)) {
            $this->email($check);
        }
    }

    private function emails(HealthCheck $c): bool
    {
        return $c->reason() === 'reconnect'
            || ($c->status === HealthCheck::CRITICAL && in_array($c->reason(), ['stale', 'scheduler'], true));
    }

    private function email(HealthCheck $check): void
    {
        try {
            $to = User::query()->where('is_active', true)->get()->filter(fn (User $u) => $u->isAdmin() && (string) $u->email !== '')
                ->pluck('email')->unique()->values()->all();
            if ($to === []) {
                return;
            }
            Mail::to($to)->send(new AdsSystemAlert($check->reason(), $check->status, (string) ($check->detail['account'] ?? $check->detail['queue'] ?? '')));
        } catch (Throwable $e) {
            // Mail may not be configured: the in-app notice already went out, the monitor must keep running.
            Log::warning('ads:health email not sent: '.mb_substr($e->getMessage(), 0, 200));
        }
    }

    /** @param  array<string, mixed>  $base */
    private function stale(AdAccount $a, mixed $lastOk, array $base): HealthCheck
    {
        $at = $lastOk !== null ? CarbonImmutable::parse((string) $lastOk, 'UTC') : ($a->last_synced_at ? CarbonImmutable::instance($a->last_synced_at) : null);
        if ($at === null) {
            return new HealthCheck("stale:{$a->id}", HealthCheck::OK, $base + ['last_ok_at' => null]); // never synced: nothing to call stale yet
        }

        $minutes = max(0, (int) $at->diffInMinutes(now(), true));
        $status = match (true) {
            $minutes > (int) config('crm.ads.health.critical_after_hours', 6) * 60 => HealthCheck::CRITICAL,
            $minutes > (int) config('crm.ads.health.stale_after_hours', 3) * 60 => HealthCheck::WARN,
            default => HealthCheck::OK,
        };

        return new HealthCheck("stale:{$a->id}", $status, $base + ['last_ok_at' => $at->toIso8601String(), 'age_minutes' => $minutes]);
    }

    /** @param  array<string, mixed>  $base */
    private function controlGap(AdAccount $a, float $control, float $itemised, string $day, array $base): HealthCheck
    {
        $pct = $control > 0 ? abs($itemised - $control) / $control * 100 : 0.0;
        $bad = $control > 0 && $pct > (float) config('crm.ads.control_tolerance_pct', 0.5);

        return new HealthCheck("control_gap:{$a->id}", $bad ? HealthCheck::WARN : HealthCheck::OK, $base + [
            'day' => $day, 'control' => round($control, 2), 'itemised' => round($itemised, 2), 'gap_pct' => round($pct, 2),
        ]);
    }

    /** @param  array<string, mixed>  $base */
    private function usageCheck(AdAccount $a, array $base): HealthCheck
    {
        $busiest = $this->usage->busiest($a->id);
        $pct = $busiest['max_pct'] ?? 0.0;

        return new HealthCheck("usage:{$a->id}", $pct >= (float) config('crm.ads.sync.admission_pct', 75) ? HealthCheck::WARN : HealthCheck::OK, $base + ['max_pct' => $pct]);
    }

    private function stuckRuns(): HealthCheck
    {
        $cutoff = now()->subSeconds((new SyncAdAccount(0))->timeout + 600);
        $n = AdsSyncRun::query()->where('status', 'running')->where('started_at', '<', $cutoff)->count();

        return new HealthCheck('stuck_runs', $n > 0 ? HealthCheck::WARN : HealthCheck::OK, ['count' => $n]);
    }

    /** A missing beat counts from the day the monitor started, so a dead scheduler is not "unknown" forever. */
    private function scheduler(): HealthCheck
    {
        $beat = Cache::get('crm:scheduler_heartbeat');
        $age = $this->ageMinutes($beat ? CarbonImmutable::parse((string) $beat) : null);

        return new HealthCheck('scheduler', $age > self::SCHEDULER_STALE_MINUTES ? HealthCheck::CRITICAL : HealthCheck::OK, ['age_minutes' => $age]);
    }

    /** @return list<HealthCheck> */
    private function queues(): array
    {
        $out = [];
        foreach (array_values(array_unique(['default', 'commercelong', SyncAdAccount::queueName()])) as $q) {
            $beat = $this->settings->get(QueueHeartbeat::key($q));
            $age = $this->ageMinutes($beat ? CarbonImmutable::parse((string) $beat) : null);
            $out[] = new HealthCheck("queue:{$q}", $age > self::QUEUE_STALE_MINUTES ? HealthCheck::CRITICAL : HealthCheck::OK, ['queue' => $q, 'age_minutes' => $age]);
        }

        return $out;
    }

    private function ageMinutes(?CarbonImmutable $at): int
    {
        $started = $this->settings->get(self::STARTED_KEY);
        $floor = $started ? CarbonImmutable::parse((string) $started) : CarbonImmutable::now();
        $latest = $at ?? $floor;

        return max(0, (int) $latest->diffInMinutes(now(), true));
    }

    private function assignmentsOverlap(): HealthCheck
    {
        $ids = DB::table('ad_account_assignments')->whereNull('ends_on')->groupBy('ad_account_id')->havingRaw('COUNT(*) > 1')
            ->pluck('ad_account_id')->map(fn ($id) => (int) $id)->values()->all();

        return new HealthCheck('assignments_overlap', $ids === [] ? HealthCheck::OK : HealthCheck::WARN, ['account_ids' => $ids]);
    }

    /** Share of the chat-origin orders of the last 14 days that carry a conversation (so they can be credited to an ad). */
    private function linkRate(): HealthCheck
    {
        $q = DB::table('orders')->where('source', 'chat')->where('created_at', '>=', now()->subDays(14));
        $orders = (clone $q)->count();
        $linked = (clone $q)->whereNotNull('conversation_id')->count();

        return new HealthCheck('link_rate', HealthCheck::OK, [
            'rate' => $orders === 0 ? null : round($linked / $orders, 4), 'orders' => $orders, 'linked' => $linked, 'days' => 14,
        ]);
    }
}
