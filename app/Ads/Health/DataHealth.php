<?php

namespace App\Ads\Health;

use App\Ads\AdsSettings;
use App\Ads\Platforms\Meta\MetaAdsApi;
use App\Ads\Platforms\Meta\UsageRecorder;
use App\Ads\Reports\AdsFilter;
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
use Illuminate\Support\Facades\Schema;
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
     * @param  list<string>  $kinds  which of the checks to run (the banner needs only the first three)
     * @return list<HealthCheck>
     */
    public function accountChecks(Collection $accounts, array $kinds = ['stale', 'reconnect', 'read_only', 'control_gap', 'usage']): array
    {
        $ids = $accounts->pluck('id')->all();
        $lastOk = in_array('stale', $kinds, true)
            ? AdsSyncRun::query()->where('status', 'ok')->whereIn('ad_account_id', $ids)->groupBy('ad_account_id')->selectRaw('ad_account_id, MAX(finished_at) as at')->pluck('at', 'ad_account_id')
            : collect();

        // D-1 (the Cairo yesterday): today is still settling at the platform, so the control is compared on the last closed day.
        $day = CarbonImmutable::now('Africa/Cairo')->subDay()->toDateString();
        $gap = in_array('control_gap', $kinds, true);
        $control = $gap ? AdAccountDaily::query()->whereIn('ad_account_id', $ids)->whereDate('date', $day)->pluck('spend', 'ad_account_id') : collect();
        $itemised = $gap ? AdDailyMetric::query()->whereIn('ad_account_id', $ids)->whereDate('date', $day)->groupBy('ad_account_id')
            ->selectRaw('ad_account_id, SUM(spend) as spend')->pluck('spend', 'ad_account_id') : collect();

        $out = [];
        foreach ($accounts as $a) {
            $base = ['account_id' => $a->id, 'account' => $a->name];
            $c = $a->connection;

            in_array('stale', $kinds, true) && $out[] = $this->stale($a, $lastOk[$a->id] ?? null, $base);
            in_array('reconnect', $kinds, true) && $out[] = new HealthCheck("reconnect:{$a->id}", $c?->status === 'needs_reconnect' ? HealthCheck::CRITICAL : HealthCheck::OK, $base);
            in_array('read_only', $kinds, true) && $out[] = new HealthCheck("read_only:{$a->id}", $c?->read_only ? HealthCheck::WARN : HealthCheck::OK, $base);
            $gap && $out[] = $this->controlGap($a, (float) ($control[$a->id] ?? 0), (float) ($itemised[$a->id] ?? 0), $day, $base);
            in_array('usage', $kinds, true) && $out[] = $this->usageCheck($a, $base);
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
        $lines = [];
        foreach ($checks as $check) {
            if ($line = $this->apply($check)) {
                $lines[] = $line;
            }
        }

        // A check that is gone (account deactivated or deleted) after it was announced bad is announced as resolved.
        $gone = AdsHealthState::query()->whereNotIn('key', array_map(fn (HealthCheck $c) => $c->key, $checks))->get();
        foreach ($gone as $state) {
            if (in_array($state->notified_status, [HealthCheck::WARN, HealthCheck::CRITICAL], true)) {
                $lines[] = [
                    'key' => $state->key, 'reason' => explode(':', $state->key, 2)[0], 'status' => HealthCheck::OK,
                    'account' => $state->detail['account'] ?? null, 'account_id' => $state->detail['account_id'] ?? null,
                    'subject' => $state->detail['queue'] ?? null, 'recovered' => true, 'resolved' => true,
                ];
            }
        }
        AdsHealthState::query()->whereIn('id', $gone->pluck('id'))->delete();

        $this->announce($lines);

        return $checks;
    }

    /**
     * Banner reasons for the accounts a report filter covers, worst first: reconnect, stale, read_only, incomplete, gap, timezone.
     * Names are capped at three per reason; `more` counts the rest. Reads the facts (no state writes), cached 60 s per filter.
     *
     * @return array{reasons: list<array{reason: string, accounts: list<string>, more: int}>}
     */
    public function forFilter(AdsFilter $f): array
    {
        if ($f->isEmpty() || ($f->platform !== null && $f->platform !== 'meta')) {
            return ['reasons' => []];
        }

        $key = 'ads:health:filter:'.sha1(json_encode([$f->fromDate(), $f->toDate(), $f->accountIds, $f->restrictBuyerId]));

        return Cache::remember($key, 60, fn () => $this->reasonsFor($f));
    }

    /** @return array{reasons: list<array{reason: string, accounts: list<string>, more: int}>} */
    private function reasonsFor(AdsFilter $f): array
    {
        $accounts = AdAccount::query()->with('connection')->where('platform', 'meta')->where('is_active', true)
            ->when($f->accountIds !== null, fn ($q) => $q->whereIn('id', $f->accountIds))->orderBy('id')->get();
        if ($accounts->isEmpty()) {
            return ['reasons' => []];
        }

        /** @var array<string, array<int, int>> $found reason => [account id => severity rank] */
        $found = [];
        foreach ($this->accountChecks($accounts, ['stale', 'reconnect', 'read_only']) as $c) {
            if ($c->isBad()) {
                $found[$c->reason()][$c->accountId()] = HealthCheck::rank($c->status);
            }
        }
        $incomplete = $this->incompleteAccounts($accounts, $f);
        foreach ($incomplete['incomplete'] as $id) {
            $found['incomplete'][$id] = 1;
        }
        foreach ($incomplete['unverified'] as $id) {
            $found['unverified'][$id] = 1;
        }
        foreach ($this->gapAccounts($accounts, $f) as $id) {
            $found['gap'][$id] = 1;
        }
        // Meta's days are the account's own days: an account outside Cairo time does not split days where the CRM does (A9).
        foreach ($accounts as $a) {
            if ($a->timezone !== null && $a->timezone !== '' && $a->timezone !== AdsFilter::TIMEZONE) {
                $found['timezone'][(int) $a->id] = 1;
            }
        }

        $names = $accounts->pluck('name', 'id');
        $labels = $names->map(fn ($n) => (string) $n)->all();
        foreach ($accounts as $a) {
            // the timezone reason names the zone each account's days follow
            $labels[(int) $a->id.'|timezone'] = $a->name.': '.$a->timezone;
        }
        $reasons = [];
        foreach (['reconnect', 'stale', 'read_only', 'incomplete', 'gap', 'timezone'] as $reason) {
            if (empty($found[$reason]) && ! ($reason === 'incomplete' && ! empty($found['unverified']))) {
                continue;
            }
            $found[$reason] ??= [];
            $ids = array_keys($found[$reason]);
            usort($ids, fn ($a, $b) => [$found[$reason][$b], (string) $names[$a]] <=> [$found[$reason][$a], (string) $names[$b]]);
            $entry = [
                'reason' => $reason,
                'accounts' => array_map(fn ($id) => (string) ($reason === 'timezone' ? $labels[$id.'|timezone'] : $names[$id]), array_slice($ids, 0, 3)),
                'more' => max(0, count($ids) - 3),
            ];
            if ($reason === 'incomplete') {
                // `accounts` = a known later start; `unverified` = never judged (complete_from null): no account in both lists
                $entry['unverified'] = array_map(fn ($id) => (string) $names[$id], array_slice($incomplete['unverified'], 0, 3));
            }
            $reasons[] = $entry;
        }

        return ['reasons' => $reasons];
    }

    /**
     * `incomplete`: accounts whose complete_from (the first day with full sync coverage up to yesterday) is after the range
     * start. `unverified`: active accounts with at least one sync run whose complete_from is still null (not judged, or
     * yesterday is not covered); an account that never synced is not flagged.
     *
     * @return array{incomplete: list<int>, unverified: list<int>}
     */
    private function incompleteAccounts(Collection $accounts, AdsFilter $f): array
    {
        if (! Schema::hasColumn('ad_accounts', 'complete_from')) {
            return ['incomplete' => [], 'unverified' => []];
        }
        $from = $f->fromDate();
        $synced = AdsSyncRun::query()->whereIn('ad_account_id', $accounts->pluck('id')->all())->distinct()->pluck('ad_account_id')->map(fn ($id) => (int) $id)->all();

        $incomplete = $unverified = [];
        foreach ($accounts as $a) {
            $c = $a->getAttribute('complete_from');
            if ($c === null) {
                in_array((int) $a->id, $synced, true) && $unverified[] = (int) $a->id;
            } elseif (substr((string) $c, 0, 10) > $from) {
                $incomplete[] = (int) $a->id;
            }
        }

        return ['incomplete' => $incomplete, 'unverified' => $unverified];
    }

    /** Accounts whose ad-level spend differs from the control total by more than the tolerance, on the closed days of the range. @return list<int> */
    private function gapAccounts(Collection $accounts, AdsFilter $f): array
    {
        $ids = $accounts->pluck('id')->all();
        $today = CarbonImmutable::now(AdsFilter::TIMEZONE)->toDateString(); // today is still settling at the platform
        $control = AdAccountDaily::query()->whereIn('ad_account_id', $ids)->whereBetween('date', [$f->fromDate(), $f->toDate()])->where('date', '<', $today)
            ->get(['ad_account_id', 'date', 'spend']);
        if ($control->isEmpty()) {
            return [];
        }
        $ads = AdDailyMetric::query()->whereIn('ad_account_id', $ids)->whereBetween('date', [$f->fromDate(), $f->toDate()])
            ->groupBy('ad_account_id', 'date')->selectRaw('ad_account_id, date, SUM(spend) as spend')->get()
            ->mapWithKeys(fn ($r) => [$r->ad_account_id.'|'.substr((string) $r->date, 0, 10) => (float) $r->spend]);

        $tolerance = (float) config('crm.ads.control_tolerance_pct', 0.5);
        $bad = [];
        foreach ($control->groupBy('ad_account_id') as $id => $rows) {
            $c = (float) $rows->sum('spend');
            $a = (float) $rows->sum(fn ($r) => $ads[$r->ad_account_id.'|'.substr((string) $r->date, 0, 10)] ?? 0);
            if ($c > 0 && abs($a - $c) / $c * 100 > $tolerance) {
                $bad[] = (int) $id;
            }
        }

        return $bad;
    }

    /** @return array{rate: ?float, orders: int, linked: int, days: int} */
    public function linkRateStats(): array
    {
        $q = DB::table('orders')->where('source', 'chat')->where('created_at', '>=', now()->subDays(14));
        $orders = (clone $q)->count();
        $linked = (clone $q)->whereNotNull('conversation_id')->count();

        return ['rate' => $orders === 0 ? null : round($linked / $orders, 4), 'orders' => $orders, 'linked' => $linked, 'days' => 14];
    }

    /** @return array<string, mixed>|null the transition to announce, when this run made one */
    private function apply(HealthCheck $check): ?array
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
            return null;
        }
        // Meta's own refusal point is 85 %: a reading between 75 and 85 shows on the banner but is not worth a notice.
        if ($check->reason() === 'usage' && $check->isBad() && (float) ($check->detail['max_pct'] ?? 0) < MetaAdsApi::USAGE_LIMIT) {
            return null;
        }

        $held = $state->since->lte(now()->subMinutes((int) config('crm.ads.health.hold_down_minutes', 30)));
        $told = $state->notified_status ?? HealthCheck::OK;
        if (! $held || $check->status === $told) {
            return null;
        }

        // Compare-and-set on notified_status: two overlapping runs announce a transition once.
        $claimed = AdsHealthState::query()->where('id', $state->id)
            ->where(fn ($q) => $told === HealthCheck::OK
                ? $q->whereNull('notified_status')->orWhere('notified_status', HealthCheck::OK)
                : $q->where('notified_status', $told))
            ->update(['notified_status' => $check->status, 'notified_at' => now()]) === 1;
        if (! $claimed) {
            return null;
        }

        return [
            'key' => $check->key,
            'reason' => $check->reason(),
            'status' => $check->status,
            'account' => $check->detail['account'] ?? null,
            'account_id' => $check->accountId(),
            'subject' => $check->detail['queue'] ?? null,
            'recovered' => ! $check->isBad(),
        ];
    }

    /**
     * One in-app notice per admin and at most one email for the whole run, listing every transition. The first line (worst
     * first) is also the notice's top-level fields, so the bell has a headline; `lines` carries all of them.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function announce(array $lines): void
    {
        if ($lines === []) {
            return;
        }
        usort($lines, fn (array $a, array $b) => HealthCheck::rank($b['status']) <=> HealthCheck::rank($a['status']));

        $this->notifier->notifyAdmins('ads.data_health', $lines[0] + ['link' => '/ads/sync', 'more' => count($lines) - 1, 'lines' => $lines]);

        $mail = array_values(array_filter($lines, fn (array $l) => ! $l['recovered'] && $this->emails($l)));
        if ($mail !== []) {
            $this->email($mail);
        }
    }

    /** @param  array<string, mixed>  $l */
    private function emails(array $l): bool
    {
        return $l['reason'] === 'reconnect'
            || ($l['status'] === HealthCheck::CRITICAL && in_array($l['reason'], ['stale', 'scheduler'], true));
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function email(array $lines): void
    {
        try {
            $to = User::query()->where('is_active', true)->get()->filter(fn (User $u) => $u->isAdmin() && (string) $u->email !== '')
                ->pluck('email')->unique()->values()->all();
            if ($to === []) {
                return;
            }
            Mail::to($to)->send(new AdsSystemAlert(array_map(fn (array $l) => [
                'reason' => $l['reason'], 'status' => $l['status'], 'subject' => (string) ($l['account'] ?? $l['subject'] ?? ''),
            ], $lines)));
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
        return new HealthCheck('link_rate', HealthCheck::OK, $this->linkRateStats());
    }
}
