<?php

namespace App\Ads\Decisions;

use App\Ads\Launch\LaunchPolicy;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Launches waiting for this user's approval (S1). Only an Ads-authority holder approves (D3, D5; LaunchPolicy::canApprove).
 *
 * Adapter over S1, which is built on a parallel branch: until `App\Ads\Launch\LaunchCounters` and `App\Models\AdLaunch`
 * exist (the merge), every answer is 0 / [] so the Decisions approvals section and the badge stay quiet; after the merge
 * they light up with no change here. Class names are strings on purpose: no hard dependency before the merge.
 */
class PendingApprovals
{
    public const COUNTERS = 'App\\Ads\\Launch\\LaunchCounters';

    public const MODEL = 'App\\Models\\AdLaunch';

    public const AWAITING = 'awaiting_approval';

    public const ITEMS = 20;

    /** One definition (final review B-m7): LaunchPolicy::canApprove. */
    public function canApprove(User $u): bool
    {
        return LaunchPolicy::canApprove($u);
    }

    public function available(): bool
    {
        return class_exists(self::COUNTERS) && class_exists(self::MODEL);
    }

    public function count(User $u): int
    {
        if (! $this->canApprove($u) || ! $this->available()) {
            return 0;
        }
        $static = (new \ReflectionMethod(self::COUNTERS, 'for'))->isStatic();
        $counters = $static ? (self::COUNTERS)::for($u) : app(self::COUNTERS)->for($u);

        return (int) ($counters['awaiting_approval'] ?? 0);
    }

    /** @return list<array{id:int, title:?string, href:string}> the newest launches awaiting approval (top of «محتاج قرار») */
    public function items(User $u, int $limit = self::ITEMS): array
    {
        if (! $this->canApprove($u) || ! $this->available()) {
            return [];
        }
        $model = self::MODEL;
        $table = (new $model)->getTable();
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'state')) {
            return [];
        }

        return $model::query()->where('state', self::AWAITING)->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($l) => [
                'id' => (int) $l->getKey(),
                'title' => $l->getAttribute('title') ?? $l->getAttribute('name'),
                'href' => '/ads/approvals?launch='.$l->getKey(),
            ])->values()->all();
    }
}
