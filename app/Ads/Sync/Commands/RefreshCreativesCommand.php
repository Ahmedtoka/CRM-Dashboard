<?php

namespace App\Ads\Sync\Commands;

use App\Ads\Platforms\AdsApiException;
use App\Ads\Sync\AdsSyncService;
use App\Models\AdAccount;
use Illuminate\Console\Command;

class RefreshCreativesCommand extends Command
{
    protected $signature = 'ads:refresh-creatives {--days=14}';

    protected $description = 'Re-fetch creative media (image, video, preview) for recently active ads';

    public function handle(AdsSyncService $sync): int
    {
        $days = max((int) $this->option('days'), 1);
        foreach (AdAccount::where('is_active', true)->get() as $a) {
            try {
                $this->line("{$a->name}: ".$sync->refreshCreatives($a, $days).' ads refreshed');
            } catch (AdsApiException $e) {
                $this->warn("{$a->name}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
