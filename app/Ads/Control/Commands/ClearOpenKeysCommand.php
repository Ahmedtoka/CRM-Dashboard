<?php

namespace App\Ads\Control\Commands;

use App\Models\AdPublication;
use Illuminate\Console\Command;

/** Ends the 24-hour duplicate guard: a publication that finished more than 24 h ago no longer blocks a new publish (W2). */
class ClearOpenKeysCommand extends Command
{
    protected $signature = 'ads:clear-open-keys';

    protected $description = 'Release the duplicate-publish key of ads published more than 24 hours ago';

    public function handle(): int
    {
        $n = AdPublication::query()->whereNotNull('open_key')->where('status', AdPublication::DONE)->where('updated_at', '<', now()->subDay())
            ->update(['open_key' => null]);
        $this->info("Released {$n} open key(s).");

        return self::SUCCESS;
    }
}
