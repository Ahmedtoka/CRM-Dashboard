<?php

namespace App\Console\Commands;

use App\Maintenance\BackupFailed;
use App\Maintenance\DatabaseBackup;
use App\Maintenance\FreshStart;
use App\Support\DataFloor;
use Illuminate\Console\Command;

/**
 * Fresh start (spec 2026-10-06 F1/F2/F3): back up the whole database, then empty the ads history, Shopify orders,
 * conversations, customers, queue and activity tables, keeping users, settings, bot config, channels, ad accounts,
 * products, materials, saved replies, tags and shipping prices.
 *
 *   php artisan crm:fresh-start                                  (asks for the phrase)
 *   php artisan crm:fresh-start --confirm="امسح كل حاجة"         (non-interactive)
 *   php artisan crm:fresh-start --force --i-have-a-backup=/path  (production: --force needs an existing backup file)
 *
 * The CRM must be in maintenance mode (`php artisan down`, workers and scheduler stopped). The backup always comes first; if it cannot be taken and verified nothing is deleted. It never starts a sync: it
 * prints the commands for the first syncs from the data floor.
 */
class FreshStartCommand extends Command
{
    public const PHRASE = 'امسح كل حاجة';

    /** Printed when the CRM is not in maintenance mode: nothing may write while the tables are emptied. */
    public const PREP_STEPS = [
        'php artisan down',
        'stop the queue workers (Cloudways: Supervisor → stop the crm-* programs, or: supervisorctl stop all)',
        'pause the scheduler (comment out the `php artisan schedule:run` cron line)',
        'then run php artisan crm:fresh-start again',
    ];

    public const AFTER_STEPS = [
        'php artisan cache:clear',
        're-enable the scheduler cron line and restart the queue workers (supervisorctl start all)',
        'php artisan up',
    ];

    protected $signature = 'crm:fresh-start
        {--confirm= : The confirmation phrase, for non-interactive runs}
        {--force : Skip the typed confirmation (production: only with --i-have-a-backup)}
        {--i-have-a-backup= : Path of an existing backup file (required with --force in production)}
        {--no-routines : Dump without --routines --events (the DB user lacks those privileges); overrides CRM_MYSQLDUMP_ROUTINES}';

    protected $description = 'Back up the database, then wipe ads history, orders, conversations, customers and queue data for a fresh start';

    public function handle(DatabaseBackup $backups, FreshStart $fresh): int
    {
        // Runtime override: an env prefix cannot reach a cached config, an option can.
        if ($this->option('no-routines')) {
            config(['crm.fresh_start.mysqldump_routines' => false]);
        }

        if (! app()->isDownForMaintenance()) {
            $this->error('Refused: the CRM is still up. Nothing was deleted. Prepare first:');
            foreach (self::PREP_STEPS as $step) {
                $this->line('  '.$step);
            }

            return self::FAILURE;
        }

        if ($this->option('force') && app()->environment('production')) {
            $proof = (string) $this->option('i-have-a-backup');
            try {
                if ($proof === '') {
                    throw new BackupFailed('no file given');
                }
                $backups->verify($proof);
            } catch (BackupFailed $e) {
                $this->error('Refused: in production --force needs --i-have-a-backup=<path of a verified .sql.gz dump> ('.$e->getMessage().'). Nothing was deleted.');

                return self::FAILURE;
            }
        }

        $before = $fresh->counts();
        $this->info('Rows now (tables to empty):');
        $this->table(['Table', 'Rows'], collect(FreshStart::WIPE)->filter(fn ($t) => isset($before[$t]))->map(fn ($t) => [$t, $before[$t]])->values()->all());

        $this->line('Taking a full database backup first…');
        try {
            $backup = $backups->take();
        } catch (BackupFailed $e) {
            $this->error('Backup failed: '.$e->getMessage());
            $this->error('No backup, no wipe: nothing was deleted.');

            return self::FAILURE;
        }
        $this->info(sprintf('Backup OK: %s (%s bytes, verified).', $backup['path'], number_format($backup['bytes'])));

        if (! $this->confirmed()) {
            $this->error('Not confirmed: nothing was deleted. The backup stays at '.$backup['path']);

            return self::FAILURE;
        }

        $result = $fresh->wipe(fn (string $table, int $n) => $this->line("  emptied {$table} ({$n} rows)"));
        $after = $fresh->counts();

        $this->info('Rows before → after:');
        $this->table(['Table', 'Kind', 'Before', 'After'], collect($before)->map(fn ($n, $t) => [
            $t,
            in_array($t, FreshStart::WIPE, true) ? 'wiped' : (in_array($t, FreshStart::KEEP, true) ? 'kept' : 'dropped'),
            $n,
            $after[$t] ?? 'dropped',
        ])->values()->all());
        $this->line(sprintf('Media files deleted: %d. Attribution backup tables dropped: %d.', $result['files'], count($result['dropped'])));

        $floor = DataFloor::start()->toDateString();
        $this->newLine();
        $this->info("Done. Nothing was synced. Run the first syncs from the data floor ({$floor}) when ready:");
        $this->line("  php artisan ads:backfill --from={$floor} --queue");
        $this->line("  php artisan shopify:reconcile-counts --from={$floor} --fix");
        $this->info('Then bring the CRM back:');
        foreach (self::AFTER_STEPS as $step) {
            $this->line('  '.$step);
        }
        $this->line('Backup: '.$backup['path']);

        return self::SUCCESS;
    }

    private function confirmed(): bool
    {
        if ($this->option('force')) {
            return true; // production already required --i-have-a-backup above
        }

        $given = $this->option('confirm');
        if ($given === null) {
            if (! $this->input->isInteractive()) {
                $this->error('Non-interactive run: pass --confirm="'.self::PHRASE.'".');

                return false;
            }
            $given = $this->ask('Everything listed above will be deleted. Type «'.self::PHRASE.'» to continue');
        }

        if (trim((string) $given) === '') {
            $this->error('The phrase was empty.');

            return false;
        }

        if (trim((string) $given) !== self::PHRASE) {
            $this->error('The phrase does not match.');

            return false;
        }

        return true;
    }
}
