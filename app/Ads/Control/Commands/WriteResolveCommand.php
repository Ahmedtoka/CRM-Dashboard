<?php

namespace App\Ads\Control\Commands;

use App\Ads\Audit\AdsAudit;
use App\Models\AdWriteAction;
use Illuminate\Console\Command;

/**
 * The manual valve for a write whose outcome is not known (until B7's sweeper/reconciler): after checking Ads Manager,
 * the operator records what really happened. Only `unknown` actions, or `executing` ones older than 10 minutes with no
 * retry pending. Clears the Run key; audited as write.resolved_by_hand.
 */
class WriteResolveCommand extends Command
{
    public const STALE_MINUTES = 10;

    protected $signature = 'ads:write-resolve
        {public_id : The write action id (ULID)}
        {--succeeded : The change is on the platform}
        {--failed : The change is not on the platform}
        {--note= : What was checked (required, stored in the audit log)}';

    protected $description = 'Record by hand the outcome of an unknown or stuck CRM ad write';

    public function handle(): int
    {
        $succeeded = (bool) $this->option('succeeded');
        $failed = (bool) $this->option('failed');
        $note = trim((string) $this->option('note'));

        if ($succeeded === $failed) {
            $this->error('Use exactly one of --succeeded or --failed.');

            return self::FAILURE;
        }
        if ($note === '') {
            $this->error('--note="..." is required: say what you checked.');

            return self::FAILURE;
        }

        $x = AdWriteAction::where('public_id', (string) $this->argument('public_id'))->first();
        if ($x === null) {
            $this->error('No write action with that id.');

            return self::FAILURE;
        }

        $stale = now()->subMinutes(self::STALE_MINUTES);
        $resolvable = $x->state === AdWriteAction::UNKNOWN
            || ($x->state === AdWriteAction::EXECUTING && $x->retry_at === null && $x->executing_at !== null && $x->executing_at->lte($stale));
        if (! $resolvable) {
            $this->error("This action is {$x->state}: only unknown actions, or executing ones older than ".self::STALE_MINUTES.' minutes with no retry pending, can be resolved by hand.');

            return self::FAILURE;
        }

        $from = $x->state;
        $to = $succeeded ? AdWriteAction::SUCCEEDED : AdWriteAction::FAILED;
        $changed = AdWriteAction::whereKey($x->id)->where('state', $from)->whereNull('retry_at')->update([
            'state' => $to,
            'finished_at' => now(),
            'open_business_key' => null,
            'error_code' => $succeeded ? null : ($x->error_code ?: 'resolved_by_hand'),
            'outcome' => json_encode(array_merge($x->outcome ?? [], ['resolved_by_hand' => $note])),
            'updated_at' => now(),
        ]) === 1;
        if (! $changed) {
            $this->error('The action changed while resolving it; look again.');

            return self::FAILURE;
        }

        $x->refresh();
        AdsAudit::record('write.resolved_by_hand', $x, ['state' => $from], ['state' => $to], ['public_id' => $x->public_id, 'note' => $note]);
        $this->info("{$x->public_id}: {$from} -> {$to}.");

        return self::SUCCESS;
    }
}
