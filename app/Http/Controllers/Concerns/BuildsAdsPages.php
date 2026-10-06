<?php

namespace App\Http\Controllers\Concerns;

use App\Ads\Access\AdsScope;
use App\Ads\AdsSettings;
use App\Ads\Control\AdWriteService;
use App\Ads\Health\Commands\GateCommand;
use App\Ads\Health\DataHealth;
use App\Ads\Platforms\AdPlatform;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsOverview;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use App\Models\MediaBuyer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Props every Ads report page shares. */
trait BuildsAdsPages
{
    /** @return array{from:string,to:string,platform:?string,buyer:?int,range:?string,accounts:list<int>} the filter as it was actually applied */
    protected function filterProps(AdsFilter $f, ?Request $r = null): array
    {
        return [
            'from' => $f->fromDate(),
            'to' => $f->toDate(),
            'platform' => $f->platform,
            'buyer' => $f->buyerId,
            'range' => $f->rangeKey(),
            'accounts' => $r !== null ? $this->pickedAccounts($r, $f) : [],
        ];
    }

    /** @return list<int> the account ids applied from `accounts` (already intersected with the user's scope) */
    protected function pickedAccounts(Request $r, AdsFilter $f): array
    {
        $asked = AdsFilter::accountIdsFrom($r->query('accounts'));

        return $f->accountIds === null ? $asked : ($asked === [] ? [] : array_values(array_intersect($f->accountIds, $asked)));
    }

    /**
     * Accounts the user may pick (their scope and the platform filter), independent of the ones picked now so the chips stay.
     *
     * @return list<array{id:int,name:string,platform:string}>
     */
    protected function accountOptions(Request $r, AdsFilter $f): array
    {
        $allowed = app(AdsScope::class)->accountIds($r->user(), $f->from, $f->to);

        return AdAccount::query()->orderBy('name')
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->when($f->platform !== null, fn ($q) => $q->where('platform', $f->platform))
            ->get(['id', 'name', 'platform'])
            ->map(fn (AdAccount $a) => ['id' => $a->id, 'name' => (string) $a->name, 'platform' => (string) $a->platform])->all();
    }

    /**
     * Every node gets can_write (Stop / Run allowed on its account today and at its level for this user), computed once for all accounts in the tree.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    protected function withCanWrite(array $nodes, Request $r): array
    {
        $writes = app(AdWriteService::class);
        $ids = [];
        $collect = function (array $list) use (&$collect, &$ids): void {
            foreach ($list as $n) {
                $ids[(int) $n['account_id']] = true;
                $collect($n['children'] ?? []);
            }
        };
        $collect($nodes);
        $can = $ids === [] ? [] : $writes->canWriteMany($r->user(), AdAccount::query()->whereIn('id', array_keys($ids))->get(['id', 'is_active', 'write_enabled', 'platform', 'external_id']));

        $levels = $writes->allowedLevels($r->user());
        $apply = function (array $list) use (&$apply, $can, $levels): array {
            return array_map(function (array $n) use (&$apply, $can, $levels) {
                $n['can_write'] = ($can[(int) $n['account_id']] ?? false) && in_array($n['level'] ?? '', $levels, true);
                $n['children'] = $apply($n['children'] ?? []);

                return $n;
            }, $list);
        };

        return $apply($nodes);
    }

    /** @return array{last_synced_at:?string, oldest:?array{account:string,last_synced_at:?string}, errors:list<array{account:string,error:string}>} */
    protected function syncProps(AdsFilter $filter, bool $withErrors): array
    {
        $active = fn () => AdAccount::query()->where('is_active', true)
            ->when($filter->accountIds !== null, fn ($q) => $q->whereIn('id', $filter->accountIds));
        $last = $active()->max('last_synced_at');

        // One fresh account must not hide a stale one: name the account that was synced longest ago (never synced first).
        $oldest = $active()->orderByRaw('last_synced_at IS NOT NULL')->orderBy('last_synced_at')->orderBy('id')->first(['id', 'name', 'last_synced_at']);

        // Connection errors carry platform text and are for the people who manage the connections.
        $errors = ! $withErrors ? [] : AdPlatformConnection::query()
            ->where('status', 'error')->orderBy('id')->get(['id', 'name', 'last_error'])
            ->map(fn (AdPlatformConnection $c) => ['account' => $c->name, 'error' => (string) $c->last_error])->all();

        return [
            'last_synced_at' => $last === null ? null : Carbon::parse($last, 'UTC')->toIso8601String(),
            'oldest' => $oldest === null ? null : [
                'account' => (string) $oldest->name,
                'last_synced_at' => $oldest->last_synced_at === null ? null : Carbon::parse($oldest->last_synced_at, 'UTC')->toIso8601String(),
            ],
            'errors' => $errors,
        ];
    }

    /**
     * Shared by every report page: buyer filter options, platform values and the display currency.
     *
     * @return array{buyers: list<array{id:int,name:string}>, platforms: list<string>, currency: string}
     */
    protected function commonProps(User $user, AdsFilter $f): array
    {
        return [
            'buyers' => $this->buyerOptions($user),
            'platforms' => $this->platformValues(),
            'currency' => app(AdsOverview::class)->currency($f),
            ...$this->bannerProps($f),
        ];
    }

    /**
     * What the data-health banner needs: the account reasons for this filter, whether the Phase A gate is still open and
     * whether the range was cut at the history start.
     *
     * @return array{data_health: array{reasons: list<array{reason: string, accounts: list<string>, more: int}>}, numbers_under_review: bool, clamped_to_history: bool}
     */
    protected function bannerProps(AdsFilter $f): array
    {
        return [
            'data_health' => app(DataHealth::class)->forFilter($f),
            'numbers_under_review' => app(AdsSettings::class)->get(GateCommand::KEY) === null,
            'clamped_to_history' => $f->clampedToHistory,
        ];
    }

    /** @return list<array{id:int,name:string}> the buyers a supervisor+ may filter by (buyers get no buyer filter) */
    protected function buyerOptions(User $user): array
    {
        if (! $user->isSupervisorOrAbove()) {
            return [];
        }

        return MediaBuyer::query()
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn (MediaBuyer $b) => ['id' => $b->id, 'name' => $b->name])->all();
    }

    /** @return list<string> */
    protected function platformValues(): array
    {
        return array_map(fn (AdPlatform $p) => $p->value, AdPlatform::cases());
    }
}
