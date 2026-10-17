<?php

namespace App\Comments\Commands;

use App\Ads\Platforms\AdPlatform;
use App\Comments\Jobs\PollTikTokAdComments;
use App\Comments\TikTok\TikTokAdCommentPoller;
use App\Models\AdAccount;
use Illuminate\Console\Command;

/**
 * Polls TikTok ad comments (E1) for every active TikTok ad account on a usable connection. Does nothing unless the
 * TikTok ads driver is live and crm.tiktok_ad_comments.enabled is on (fakes never call TikTok).
 */
class PollTikTokAdCommentsCommand extends Command
{
    protected $signature = 'tiktok:poll-ad-comments {--account= : Ad account id} {--now : Run inline and print the result}';

    protected $description = 'Read new TikTok ad comments into the Comments page';

    public function handle(TikTokAdCommentPoller $poller): int
    {
        if (! self::enabled()) {
            $this->info('TikTok ad comments are off (crm.tiktok_ad_comments.enabled, or the TikTok ads driver is not live).');

            return self::SUCCESS;
        }

        $accounts = self::accounts()->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))->get();
        foreach ($accounts as $account) {
            if ($this->option('now')) {
                $r = $poller->poll($account);
                $this->line(sprintf('#%d %s: %d ad groups, %d new, %d updated%s', $account->id, $account->name, $r['adgroups'], $r['ingested'], $r['updated'], $r['skipped'] ? " (stopped: {$r['skipped']})" : ''));
            } else {
                PollTikTokAdComments::dispatch($account->id);
            }
        }
        if ($accounts->isEmpty()) {
            $this->info('No TikTok ad accounts to poll.');
        }

        return self::SUCCESS;
    }

    public static function enabled(): bool
    {
        return (bool) config('crm.tiktok_ad_comments.enabled', true) && config('crm.ads.drivers.tiktok') === 'live';
    }

    /** @return \Illuminate\Database\Eloquent\Builder<AdAccount> */
    public static function accounts()
    {
        return AdAccount::query()->with('connection')
            ->where('platform', AdPlatform::Tiktok->value)
            ->where('is_active', true)
            ->whereHas('connection', fn ($q) => $q->whereNotIn('status', ['disabled', 'needs_reconnect']))
            ->orderBy('id');
    }
}
