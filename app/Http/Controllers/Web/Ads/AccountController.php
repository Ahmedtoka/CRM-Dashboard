<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Buyers\AssignmentService;
use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Sync\AdsSyncService;
use App\Ads\Sync\SyncAdAccount;
use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdDailyMetric;
use App\Models\AdPlatformConnection;
use App\Models\MediaBuyer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function index(): Response
    {
        $spend = AdDailyMetric::query()
            ->where('date', '>=', CarbonImmutable::now('Africa/Cairo')->subDays(29)->toDateString())
            ->groupBy('ad_account_id')->selectRaw('ad_account_id, SUM(spend) as spend')
            ->pluck('spend', 'ad_account_id');

        $open = AdAccountAssignment::with('buyer:id,name')->whereNull('ends_on')->get()->keyBy('ad_account_id');

        $connections = AdPlatformConnection::with('accounts')->orderBy('id')->get()
            ->map(fn (AdPlatformConnection $c) => [
                'id' => $c->id,
                'platform' => $c->platform,
                'name' => $c->name,
                'status' => $c->status,
                'last_error' => $c->last_error,
                'last_synced_at' => $c->last_synced_at?->toIso8601String(),
                'driver' => config('crm.ads.drivers.'.$c->platform, 'fake') === 'live' ? 'live' : 'fake',
                'has_token' => ! empty($c->credentials['access_token'] ?? $c->credentials['refresh_token'] ?? null),
                'configured' => $this->configured($c),
                'accounts' => $c->accounts->sortBy('id')->values()->map(fn (AdAccount $a) => [
                    'id' => $a->id,
                    'external_id' => $a->external_id,
                    'name' => $a->name,
                    'currency' => $a->currency,
                    'status' => $a->status,
                    'is_active' => $a->is_active,
                    'last_synced_at' => $a->last_synced_at?->toIso8601String(),
                    'buyer' => ($b = $open[$a->id]->buyer ?? null) ? ['id' => $b->id, 'name' => $b->name] : null,
                    'history' => $this->assignments->history($a),
                    'spend_30d' => round((float) ($spend[$a->id] ?? 0), 2),
                ])->all(),
            ])->all();

        return Inertia::render('Ads/Accounts', [
            'connections' => $connections,
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
            $values['credentials'] = $this->credentials($connection->platform, (array) $data['credentials'], $connection->credentials ?? []);
        }
        if (isset($values['credentials']) && $values['credentials'] !== ($connection->credentials ?? [])) {
            // New credentials are untested: drop the stale error badge until Test or Sync runs.
            $values += ['status' => 'pending', 'last_error' => null];
        }
        $connection->update($values);

        return back()->with('status', __('ads.flash.saved'));
    }

    public function test(Request $request, AdPlatformConnection $connection, DriverFactory $drivers): RedirectResponse|JsonResponse
    {
        try {
            $error = $drivers->for(AdPlatform::from($connection->platform))->test($connection);
        } catch (AdsApiException $e) {
            $error = $e->getMessage();
        }
        $error = $error === null ? null : AdsSyncService::scrub($error);

        $connection->update($error === null
            ? ['status' => 'connected', 'last_error' => null]
            : ['status' => 'error', 'last_error' => $error]);

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
            ->each(fn (int $id) => SyncAdAccount::dispatch($id));

        return back()->with('status', __('ads.flash.sync_queued'));
    }

    public function destroy(AdPlatformConnection $connection): RedirectResponse
    {
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
        SyncAdAccount::dispatch($account->id);

        return back()->with('status', __('ads.flash.sync_queued'));
    }

    /** @param  list<int|string>  $known  account ids that existed before the connection's discovery */
    private function dispatchBackfill(AdPlatformConnection $connection, array $known): void
    {
        $days = (int) config('crm.ads.backfill_days', 90);

        $connection->accounts()->where('is_active', true)->whereNotIn('id', $known)->pluck('id')
            ->each(fn (int $id) => SyncAdAccount::dispatch($id, $days, 'backfill'));
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

    /** @return array<string, bool> which credential fields hold a value; the values themselves never leave the server */
    private function configured(AdPlatformConnection $c): array
    {
        $out = [];
        foreach (self::FIELDS[$c->platform] ?? [] as $f) {
            $out[$f['key']] = ! empty($c->credentials[$f['key']] ?? null);
        }

        return $out;
    }
}
