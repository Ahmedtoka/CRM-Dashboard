<?php

namespace App\Simulator\LoadTest;

use App\Enums\Platform;
use App\Models\ChannelAccount;
use Illuminate\Support\Collection;

/**
 * The dedicated «تيست» channels of the production load test (2026-10-07). Every simulated
 * customer message (the Simulator page, `crm:simulate`, `crm:load-test`) lands on one of
 * these, never on a real page / account / number: they are found by their own
 * `external_id` (`loadtest-{platform}`), never by platform alone, and they are flagged
 * `is_load_test`, which makes ChannelRegistry::adapterFor() hand every send to the fake
 * adapter whatever the global channel driver says.
 */
final class LoadTestChannels
{
    public const PREFIX = 'loadtest-';

    /** The platforms the load test writes on (TikTok stays available to the Simulator only). */
    public const PLATFORMS = [Platform::Facebook, Platform::Instagram, Platform::WhatsApp];

    private const NAMES = [
        'facebook' => 'تيست — ماسنجر',
        'instagram' => 'تيست — إنستجرام',
        'whatsapp' => 'تيست — واتساب',
        'tiktok' => 'تيست — تيك توك',
    ];

    /** Short, so a worker that started before the channels existed sees them within half a minute. */
    private const MEMO_SECONDS = 30;

    /**
     * Whether this channel account is a load-test channel: one query per MEMO_SECONDS, memoized on
     * the container (a fresh app — each test — starts empty; a long-lived worker refreshes it).
     */
    public static function isTestAccountId(?int $channelAccountId): bool
    {
        if ($channelAccountId === null) {
            return false;
        }

        $memo = app()->bound(self::MEMO) ? app(self::MEMO) : null;

        if (! is_array($memo) || time() - $memo['at'] > self::MEMO_SECONDS) {
            $memo = ['at' => time(), 'ids' => ChannelAccount::query()->where('is_load_test', true)->pluck('id')->map(fn ($id) => (int) $id)->all()];
            app()->instance(self::MEMO, $memo);
        }

        return in_array($channelAccountId, $memo['ids'], true);
    }

    public static function forgetIds(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    private const MEMO = 'loadtest.channel_ids';

    public static function externalId(Platform $p): string
    {
        return self::PREFIX.$p->value;
    }

    /** The platform's test account, created on first use. Never any other row. */
    public static function account(Platform $p): ChannelAccount
    {
        $account = ChannelAccount::query()->firstOrCreate(
            ['platform' => $p->value, 'external_id' => self::externalId($p)],
            ['name' => self::NAMES[$p->value], 'driver' => 'fake', 'status' => 'connected', 'is_load_test' => true],
        );

        if ($account->wasRecentlyCreated) {
            self::forgetIds();
        }

        // A row with our external id that somehow lost its flags is put back, never trusted as-is.
        if (! $account->is_load_test || $account->driver !== 'fake') {
            $account->forceFill(['is_load_test' => true, 'driver' => 'fake'])->save();
        }

        return $account;
    }

    /** @return Collection<int, ChannelAccount> the three load-test channels */
    public static function ensureAll(): Collection
    {
        return collect(self::PLATFORMS)->map(fn (Platform $p) => self::account($p));
    }

    /** Whether every given channel external id belongs to a load-test account of this platform. */
    public static function allTest(Platform $p, array $channelIds): bool
    {
        $ids = array_values(array_unique(array_map('strval', $channelIds)));

        if ($ids === [] || in_array('', $ids, true)) {
            return false;
        }

        return ChannelAccount::query()->where('platform', $p->value)->where('is_load_test', true)
            ->whereIn('external_id', $ids)->count() === count($ids);
    }
}
