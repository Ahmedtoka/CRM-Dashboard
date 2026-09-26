<?php

namespace App\Shopify\Commands;

use App\Shopify\Sync\BulkImporter;
use App\Shopify\Sync\OrderReconciler;
use Illuminate\Console\Command;

/**
 * Shopify vs CRM order counts per shop-local day and per status, e.g.
 * `php artisan shopify:reconcile-counts --from=2026-09-01 --to=2026-09-30`.
 * `--fix` queues a bulk import of the range when anything differs.
 */
class ReconcileCountsCommand extends Command
{
    protected $signature = 'shopify:reconcile-counts
        {--from= : First day (Y-m-d, shop time); default the 1st of this month}
        {--to= : Last day (Y-m-d, shop time); default today}
        {--missing : Also list the order numbers missing from the CRM}
        {--fix : Import the whole range again when the counts differ}';

    protected $description = 'Compare Shopify order counts with the CRM, day by day and by payment/fulfillment status';

    public function handle(OrderReconciler $reconciler, BulkImporter $importer): int
    {
        $today = now(BulkImporter::shopTimezone());
        $from = (string) ($this->option('from') ?: $today->copy()->startOfMonth()->toDateString());
        $to = (string) ($this->option('to') ?: $today->toDateString());

        $result = $reconciler->compare($from, $to, (bool) $this->option('missing'));

        $this->table(['Day', 'Shopify', 'CRM', 'Diff'], array_map(
            fn ($d) => [$d['date'], $d['shopify'], $d['crm'], $d['diff'] === 0 ? '✓' : sprintf('%+d', $d['diff'])],
            $result['days'],
        ));
        $this->table(['Status', 'Shopify', 'CRM', 'Diff'], array_map(
            fn ($s) => ["{$s['group']}:{$s['key']}", $s['shopify'], $s['crm'], $s['diff'] === 0 ? '✓' : sprintf('%+d', $s['diff'])],
            $result['statuses'],
        ));

        $this->line(sprintf('Total %s → %s (%s): Shopify %d, CRM %d.', $result['from'], $result['to'], $result['timezone'], $result['totals']['shopify'], $result['totals']['crm']));

        if ($result['missing'] !== []) {
            $this->warn('Missing from the CRM: '.implode(' ', array_map(fn ($m) => $m['name'] ?? $m['id'], $result['missing'])));
        }

        if ($result['extra'] !== []) {
            $this->warn('In the CRM but not in Shopify for that day: '.implode(' ', array_map(fn ($m) => $m['name'] ?? $m['id'], $result['extra'])));
        }

        if ($result['matched']) {
            $this->info('Matched: every day and every status agrees with Shopify.');

            return self::SUCCESS;
        }

        if ($this->option('fix')) {
            $importer->importOrders($result['from'], $result['to']);
            $this->info('Queued a bulk import of the range; run this command again when it finishes.');
        }

        return self::FAILURE;
    }
}
