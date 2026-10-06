<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Buyers\AssignmentService;
use App\Ads\Health\DataHealth;
use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\TokenInvalid;
use App\Ads\Sync\AdsSyncService;
use App\Ads\Sync\ConnectionHealth;
use App\Ads\Sync\QueueInspector;
use App\Ads\Sync\SyncAdAccount;
use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdAction;
use App\Models\AdDailyMetric;
use App\Models\AdPlatformConnection;
use App\Models\AdPublication;
use App\Models\AdsSyncRun;
use App\Models\AdWriteAction;
use App\Models\MediaBuyer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Ad platform connections, their accounts, who holds each account and manual syncs. Supervisor and up. */
class AccountController extends Controller
{
    /** Credential fields per platform; `secret` fields are write-only (never sent back to the browser). */
    private const FIELDS = [
        'meta' => [
            ['key' => 'access_token', 'secret' => true, 'required' => true],
        ],
        'tiktok' => [
            ['key' => 'access_token', 'secret' => true, 'required' => true],
            ['key' => 'advertiser_ids', 'secret' => false, 'required' => true],
        ],
        'google' => [
            ['key' => 'developer_token', 'secret' => true, 'required' => false],
            ['key' => 'client_id', 'secret' => false, 'required' => true],
            ['key' => 'client_secret', 'secret' => true, 'required' => true],
            ['key' => 'refresh_token', 'secret' => true, 'required' => true],
            ['key' => 'login_customer_id', 'secret' => false, 'required' => false],
        ],
    ];

    public function __construct(private readonly AssignmentService $assignments) {}

    public function index(Request $request, QueueInspector $queue): Response
    {
        $filters = $this->filters($request);
        $picked = $filters['accounts'];

        $spend = AdDailyMetric::query()
            ->whereBetween('date', [$filters['from'], $filters['to']])
            ->groupBy('ad_account_id')->selectRaw('ad_account_id, SUM(spend) as spend')
            ->pluck('spend', 'ad_account_id');

        $open = AdAccountAssignment::with('buyer:id,name')->whereNull('ends_on')->get()->keyBy('ad_account_id');
        $lastRuns = $this->lastRuns();

        $all = AdPlatformConnection::with('accounts')->orderBy('id')->get();
        $shown = fn (AdPlatformConnection $c) => $c->accounts->when($picked !== [], fn ($accounts) => $accounts->whereIn('id', $picked))->sortBy('id')->values();

        $connections = $all
            ->map(fn (AdPlatformConnection $c) => [
                'id' => $c->id,
                'platform' => $c->platform,
                'name' => $c->name,
                'status' => $c->status,
                'last_error' => $c->last_error,
                'last_synced_at' => $c->last_synced_at?->toIso8601String(),
                'driver' => config('crm.ads.drivers.'.$c->platform, 'fake') === 'live' ? 'live' : 'fake',
                ...$this->credentialState($c),
                'read_only' => (bool) $c->read_only,
                'token_health' => $this->tokenHealth($c),
                'accounts' => $shown($c)->map(fn (AdAccount $a) => [
                    'id' => $a->id,
                    'platform' => $a->platform,
                    'external_id' => $a->external_id,
                    'name' => $a->name,
                    'currency' => $a->currency,
                    'status' => $a->status,
                    'is_active' => $a->is_active,
                    'last_synced_at' => $a->last_synced_at?->toIso8601String(),
                    'buyer' => ($b = $open[$a->id]->buyer ?? null) ? ['id' => $b->id, 'name' => $b->name] : null,
                    'history' => $this->assignments->history($a),
                    // Spend in the page's range (account currency).
                    'spend' => round((float) ($spend[$a->id] ?? 0), 2),
                    'last_run' => $lastRuns[$a->id] ?? null,
                ])->all(),
            ])->all();

        $accounts = collect($connections)->flatMap(fn (array $c) => $c['accounts']);

        return Inertia::render('Ads/Accounts', [
            'connections' => $connections,
            'filters' => $filters,
            'summary' => [
                'accounts' => $accounts->count(),
                'active' => $accounts->where('is_active', true)->count(),
                'spend' => $accounts->groupBy('currency')->map(fn ($rows, $currency) => ['currency' => (string) $currency, 'amount' => round((float) $rows->sum('spend'), 2)])
                    ->sortBy('currency')->values()->all(),
                'last_sync' => $accounts->pluck('last_synced_at')->filter()->max(),
                // Accounts whose latest finished sync failed, plus connections that need attention.
                'errors' => $accounts->filter(fn (array $a) => ($a['last_run']['status'] ?? null) === 'error')->count()
                    + $all->filter(fn (AdPlatformConnection $c) => in_array($c->status, ['error', 'needs_reconnect'], true))->count(),
            ],
            'account_options' => $all->flatMap(fn (AdPlatformConnection $c) => $c->accounts)->sortBy('name')->values()
                ->map(fn (AdAccount $a) => ['id' => $a->id, 'name' => $a->name, 'platform' => $a->platform])->all(),
            // Share of the last 14 days' chat orders that carry a conversation (so an ad can be credited).
            'link_rate' => app(DataHealth::class)->linkRateStats(),
            // Accounts with a sync running now or a sync job still waiting in the queue.
            'syncing' => collect($queue->waiting())->pluck('account_id')
                ->merge(AdsSyncRun::query()->where('status', 'running')->pluck('ad_account_id'))
                ->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all(),
            // Archived buyers stay listed only as the current holder of an account (the page shows active ones plus that holder).
            'buyers' => MediaBuyer::query()
                ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $open->pluck('media_buyer_id')->filter()->values()))
                ->orderBy('name')->get(['id', 'name', 'is_active'])
                ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'is_active' => (bool) $b->is_active])->all(),
            'platforms' => array_map(fn (AdPlatform $p) => [
                'value' => $p->value,
                'label' => $p->label(),
                'fields' => array_map(fn (array $f) => [
                    'key' => $f['key'],
                    'label' => __('ads.credentials.'.$f['key']),
                    'secret' => $f['secret'],
                ], self::FIELDS[$p->value]),
            ], AdPlatform::cases()),
        ]);
    }

    public function store(Request $request, AdsSyncService $sync): RedirectResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::enum(AdPlatform::class)],
            'name' => ['required', 'string', 'max:120'],
            'credentials' => ['required', 'array'],
        ]);
        $credentials = $this->credentials($data['platform'], (array) $data['credentials'], []);

        // A failed first sync leaves an errored, empty row: a resubmit retries that row instead of adding a twin.
        $connection = AdPlatformConnection::where('platform', $data['platform'])->where('status', 'error')->doesntHave('accounts')->orderBy('id')->first();
        if ($connection !== null) {
            $connection->update(['name' => $data['name'], 'credentials' => $credentials, 'status' => 'connected', 'last_error' => null]);
        } else {
            $connection = AdPlatformConnection::create([
                'platform' => $data['platform'], 'name' => $data['name'], 'credentials' => $credentials, 'status' => 'connected',
            ]);
        }

        $known = AdAccount::pluck('id')->all();
        try {
            $sync->syncAccounts($connection);
        } catch (AdsApiException $e) {
            // syncAccounts already marked the connection as error; the form shows the platform's message.
            throw ValidationException::withMessages(['credentials' => AdsSyncService::scrub($e->getMessage())]);
        }
        $this->dispatchBackfill($connection, $known);

        return back()->with('status', __('ads.flash.connected', ['count' => $connection->accounts()->count()]));
    }

    public function update(Request $request, AdPlatformConnection $connection): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'credentials' => ['sometimes', 'array'],
        ]);

        $values = [];
        if (isset($data['name'])) {
            $values['name'] = $data['name'];
        }
        if (isset($data['credentials'])) {
            $values['credentials'] = $this->credentials($connection->platform, (array) $data['credentials'], $this->storedCredentials($connection) ?? []);
        }
        if (isset($values['credentials']) && $values['credentials'] !== ($this->storedCredentials($connection) ?? [])) {
            // New credentials are untested: drop the stale error badge until Test or Sync runs.
            $values += ['status' => 'pending', 'last_error' => null, 'needs_reconnect_at' => null, 'probed_at' => null, 'token_valid' => null, 'token_scopes' => null, 'token_checked_at' => null, 'read_only' => false];
        }
        if (isset($values['credentials']) && $this->storedCredentials($connection) === null) {
            // The old ciphertext cannot be read, and an Eloquent save would try to compare against it: replace it first.
            DB::table('ad_platform_connections')->where('id', $connection->id)->update(['credentials' => Crypt::encryptString(json_encode($values['credentials']))]);
            unset($values['credentials']);
            $connection->refresh();
        }
        $connection->forceFill($values)->save();

        return back()->with('status', __('ads.flash.saved'));
    }

    public function test(Request $request, AdPlatformConnection $connection, DriverFactory $drivers): RedirectResponse|JsonResponse
    {
        try {
            $error = $drivers->for(AdPlatform::from($connection->platform))->test($connection);
        } catch (AdsApiException $e) {
            $error = $e->getMessage();
            $dead = $e instanceof TokenInvalid;
        }
        $error = $error === null ? null : AdsSyncService::scrub($error);

        if ($error === null) {
            $connection->update(['status' => 'connected', 'last_error' => null, 'needs_reconnect_at' => null]);
        } elseif ($dead ?? false) {
            ConnectionHealth::markNeedsReconnect($connection, $error);
        } else {
            ConnectionHealth::markError($connection, $error);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => $error === null, 'error' => $error]);
        }

        return $error === null
            ? back()->with('status', __('ads.flash.test_ok'))
            : back()->withErrors(['connection' => $error]);
    }

    /** Re-discover the connection's accounts, backfill the new ones and queue a recent sync for the rest. */
    public function sync(AdPlatformConnection $connection, AdsSyncService $sync): RedirectResponse
    {
        $known = AdAccount::pluck('id')->all();
        try {
            $sync->syncAccounts($connection);
        } catch (AdsApiException $e) {
            return back()->withErrors(['connection' => AdsSyncService::scrub($e->getMessage())]);
        }

        $this->dispatchBackfill($connection, $known);
        $connection->accounts()->where('is_active', true)->whereIn('id', $known)->pluck('id')
            ->each(fn (int $id) => SyncAdAccount::dispatch($id, 3, 'recent', 'manual', auth()->id()));

        return back()->with('status', __('ads.flash.sync_queued'));
    }

    /**
     * A connection with spend history is stopped, never deleted: the delete would cascade through its
     * accounts, ads and daily metrics and unlink the orders, wiping past spend and buyer numbers for
     * good. Its accounts stop syncing and stay in every report; a new token or a new connection to the
     * same accounts picks them up again. One with no metrics is deleted as before.
     */
    public function destroy(AdPlatformConnection $connection): RedirectResponse
    {
        // Spend, published ads and Stop/Run audit rows are history: the connection is archived, never deleted.
        $hasHistory = AdDailyMetric::query()->whereIn('ad_account_id', $connection->accounts()->select('id'))->exists()
            || AdPublication::query()->whereIn('ad_account_id', $connection->accounts()->select('id'))->exists()
            || AdAction::query()->whereIn('ad_account_id', $connection->accounts()->select('id'))->exists()
            || AdWriteAction::query()->whereIn('ad_account_id', $connection->accounts()->select('id'))->exists();

        if ($hasHistory) {
            DB::transaction(function () use ($connection) {
                $connection->update(['status' => 'disabled', 'last_error' => null]);
                // Only the accounts this archive switches off carry the reason: one stopped by hand stays stopped on rediscovery.
                $connection->accounts()->where('is_active', true)->update(['is_active' => false, 'deactivated_reason' => 'connection_archived']);
            });

            return back()->with('status', __('ads.flash.archived'));
        }

        $connection->delete();

        return back()->with('status', __('ads.flash.deleted'));
    }

    public function assign(Request $request, AdAccount $account): RedirectResponse
    {
        $data = $request->validate([
            'media_buyer_id' => ['nullable', 'integer', Rule::exists('media_buyers', 'id')],
            'starts_on' => ['required', 'date_format:Y-m-d'],
        ]);

        $buyer = $data['media_buyer_id'] === null ? null : MediaBuyer::findOrFail($data['media_buyer_id']);
        $this->assignments->assign($account, $buyer, CarbonImmutable::parse($data['starts_on'], 'Africa/Cairo')->startOfDay());

        return back()->with('status', __('ads.flash.assigned'));
    }

    public function updateAccount(Request $request, AdAccount $account): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $account->update(['is_active' => $data['is_active']]);

        return back()->with('status', __('ads.flash.saved'));
    }

    public function syncAccount(AdAccount $account): RedirectResponse
    {
        SyncAdAccount::dispatch($account->id, 3, 'recent', 'manual', auth()->id());

        return back()->with('status', __('ads.flash.sync_queued'));
    }

    /**
     * The page's one «سنك» (F6): the picked active accounts, or (none picked) every connection re-discovered first —
     * new accounts get the backfill, known ones a recent sync. A connection the platform refuses is reported and
     * skipped; its known accounts still sync. Returns what to poll.
     */
    public function syncMany(Request $request, AdsSyncService $sync): JsonResponse
    {
        $data = $request->validate(['accounts' => ['sometimes', 'array'], 'accounts.*' => ['integer']]);
        $since = now();
        $picked = array_values(array_unique(array_map('intval', $data['accounts'] ?? [])));
        $errors = [];
        $ids = [];

        if ($picked === []) {
            foreach (AdPlatformConnection::query()->where('status', '!=', 'disabled')->orderBy('id')->get() as $connection) {
                $known = AdAccount::pluck('id')->all();
                try {
                    $sync->syncAccounts($connection);
                } catch (AdsApiException $e) {
                    $errors[] = ['connection' => $connection->name, 'message' => AdsSyncService::scrub($e->getMessage())];

                    continue;
                }
                $new = $connection->accounts()->where('is_active', true)->whereNotIn('id', $known)->pluck('id')->all();
                $this->dispatchBackfill($connection, $known);
                $ids = [...$ids, ...$new];
            }
            $recent = AdAccount::query()->where('is_active', true)->whereNotIn('id', $ids)->orderBy('id')->pluck('id')->all();
        } else {
            $recent = AdAccount::query()->where('is_active', true)->whereIn('id', $picked)->orderBy('id')->pluck('id')->all();
        }

        foreach ($recent as $id) {
            SyncAdAccount::dispatch($id, 3, 'recent', 'manual', auth()->id());
        }
        $ids = [...$ids, ...$recent];
        sort($ids);

        return response()->json(['since' => $since->toIso8601String(), 'accounts' => $ids, 'errors' => $errors]);
    }

    /**
     * Where each account's sync stands since `since`: running (a run is going now), done / error (the latest run
     * started or finished since then; `skipped` means another run covered it), else queued. `done` counts finished
     * accounts whatever the outcome, so the bar reaches the end.
     */
    public function syncStatus(Request $request): JsonResponse
    {
        $data = $request->validate(['accounts' => ['required'], 'since' => ['nullable', 'date']]);
        $since = isset($data['since']) ? CarbonImmutable::parse($data['since']) : CarbonImmutable::now()->subMinutes(10);
        $ids = $this->idList($data['accounts']);

        $accounts = AdAccount::query()->whereIn('id', array_map('intval', $ids))->orderBy('name')->get(['id', 'name', 'platform']);
        $runs = AdsSyncRun::query()->whereIn('ad_account_id', $accounts->pluck('id'))
            ->where(fn ($q) => $q->where('status', 'running')->orWhere('started_at', '>=', $since)->orWhere('finished_at', '>=', $since))
            ->orderByDesc('id')->get()->groupBy('ad_account_id');

        $rows = $accounts->map(function (AdAccount $a) use ($runs) {
            $mine = $runs->get($a->id, collect());
            $latest = $mine->first();
            $state = match (true) {
                $mine->contains('status', 'running') => 'running',
                $latest === null => 'queued',
                $latest->status === 'error' => 'error',
                default => 'done',
            };

            return [
                'id' => $a->id,
                'name' => $a->name,
                'platform' => $a->platform,
                'state' => $state,
                'error' => $state === 'error' && $latest->error !== null ? AdsSyncService::scrub($latest->error) : null,
            ];
        })->values();

        $done = $rows->whereIn('state', ['done', 'error'])->count();

        return response()->json(['accounts' => $rows->all(), 'done' => $done, 'total' => $rows->count(), 'finished' => $done === $rows->count()]);
    }

    /** @return array{from: string, to: string, accounts: list<int>} range (default: this Cairo month) and picked accounts */
    private function filters(Request $request): array
    {
        $today = CarbonImmutable::now('Africa/Cairo');
        $from = $today->startOfMonth()->toDateString();
        $to = $today->toDateString();
        $valid = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && strtotime($v) !== false;
        $f = $request->query('from');
        $t = $request->query('to');
        if ($valid($f) && $valid($t) && $f <= $t) {
            [$from, $to] = [$f, $t];
        }

        $picked = array_map('intval', $this->idList($request->query('accounts')));
        $picked = $picked === [] ? [] : AdAccount::query()->whereIn('id', $picked)->orderBy('id')->pluck('id')->all();

        return ['from' => $from, 'to' => $to, 'accounts' => array_values($picked)];
    }

    /** @return array<int, array{status: string, error: ?string, finished_at: ?string}> the latest finished run per account */
    private function lastRuns(): array
    {
        $latest = AdsSyncRun::query()->whereIn('status', ['ok', 'error'])->groupBy('ad_account_id')->selectRaw('MAX(id) as id');

        return AdsSyncRun::query()->whereIn('id', $latest)->get()
            ->mapWithKeys(fn (AdsSyncRun $r) => [$r->ad_account_id => [
                'status' => $r->status,
                'error' => $r->status === 'error' && $r->error !== null ? AdsSyncService::scrub($r->error) : null,
                'finished_at' => $r->finished_at?->toIso8601String(),
            ]])->all();
    }

    /** @param  list<int|string>  $known  account ids that existed before the connection's discovery */
    private function dispatchBackfill(AdPlatformConnection $connection, array $known): void
    {
        $days = (int) config('crm.ads.backfill_days', 90);

        $connection->accounts()->where('is_active', true)->whereNotIn('id', $known)->pluck('id')
            ->each(fn (int $id) => SyncAdAccount::dispatch($id, $days, 'backfill', 'manual', auth()->id()));
    }

    /**
     * Allowed keys only; a blank value keeps the stored one. tiktok advertiser_ids arrives as a comma list.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function credentials(string $platform, array $input, array $stored): array
    {
        $out = $stored;
        $errors = [];

        foreach (self::FIELDS[$platform] as $field) {
            $key = $field['key'];
            $raw = $input[$key] ?? null;
            $value = $key === 'advertiser_ids' ? $this->idList($raw) : (is_string($raw) ? trim($raw) : '');

            if ($value === '' || $value === []) {
                if ($field['required'] && empty($stored[$key])) {
                    $errors['credentials.'.$key] = __('validation.required', ['attribute' => __('ads.credentials.'.$key)]);
                }

                continue;
            }
            $out[$key] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return array_intersect_key($out, array_flip(array_column(self::FIELDS[$platform], 'key')));
    }

    /** @return list<string> */
    private function idList(mixed $raw): array
    {
        $parts = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw);

        return array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), $parts ?: []), fn ($v) => $v !== '')));
    }

    /** Stored credentials, or null when the ciphertext cannot be decrypted (APP_KEY changed, damaged column). */
    private function storedCredentials(AdPlatformConnection $c): ?array
    {
        try {
            return $c->credentials ?? [];
        } catch (DecryptException) {
            return null;
        }
    }

    /** @return array{has_token: bool, configured: array<string, bool>, credentials_unreadable: bool} */
    private function credentialState(AdPlatformConnection $c): array
    {
        $stored = $this->storedCredentials($c);
        if ($stored === null) {
            return ['has_token' => false, 'configured' => array_fill_keys(array_column(self::FIELDS[$c->platform] ?? [], 'key'), false), 'credentials_unreadable' => true];
        }

        return [
            'has_token' => ! empty($stored['access_token'] ?? $stored['refresh_token'] ?? null),
            'configured' => $this->configured($c, $stored),
            'credentials_unreadable' => false,
        ];
    }

    /** @return array<string, mixed> what the last probe learned about the token; no token, no secret */
    private function tokenHealth(AdPlatformConnection $c): array
    {
        return [
            'valid' => $c->token_valid,
            'type' => $c->token_type,
            'scopes' => $c->token_scopes ?? [],
            'expires_at' => $c->token_expires_at?->toIso8601String(),
            'data_access_expires_at' => $c->data_access_expires_at?->toIso8601String(),
            'checked_at' => $c->token_checked_at?->toIso8601String(),
        ];
    }

    /** @return array<string, bool> which credential fields hold a value; the values themselves never leave the server */
    private function configured(AdPlatformConnection $c, array $stored): array
    {
        $out = [];
        foreach (self::FIELDS[$c->platform] ?? [] as $f) {
            $out[$f['key']] = ! empty($stored[$f['key']] ?? null);
        }

        return $out;
    }
}
