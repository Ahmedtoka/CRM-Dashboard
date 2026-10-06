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
 * The backup always comes first; if it cannot be taken and verified nothing is deleted. It never starts a sync: it
 * prints the commands for the first syncs from the data floor.
 */
class FreshStartCommand extends Command
{
    public const PHRASE = 'امسح كل حاجة';

    protected $signature = 'crm:fresh-start
        {--confirm= : The confirmation phrase, for non-interactive runs}
        {--force : Skip the typed confirmation (production: only with --i-have-a-backup)}
        {--i-have-a-backup= : Path of an existing backup file (required with --force in production)}';

    protected $description = 'Back up the database, then wipe ads history, orders, conversations, customers and queue data for a fresh start';

    public function handle(DatabaseBackup $backups, FreshStart $fresh): int
    {
        if ($this->option('force') && app()->environment('production')) {
            $proof = (string) $this->option('i-have-a-backup');
            if ($proof === '' || ! is_file($proof) || ! is_readable($proof) || filesize($proof) === 0) {
                $this->error('Refused: in production --force needs --i-have-a-backup=<path of an existing, non-empty backup file>. Nothing was deleted.');

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
        $this->line('Backup: '.$backup['path']);

        return self::SUCCESS;
    }

    private function confirmed(): bool
    {
        if ($this->option('force')) {
            return true; // production already required --i-have-a-backup above
        }

        $given = $this->option('confirm');
        if ($given === null && $this->input->isInteractive()) {
            $given = $this->ask('Everything listed above will be deleted. Type «'.self::PHRASE.'» to continue');
        }

        if ($given === null) {
            $this->error('Non-interactive run: pass --confirm="'.self::PHRASE.'".');

            return false;
        }

        if (trim((string) $given) !== self::PHRASE) {
            $this->error('The phrase does not match.');

            return false;
        }

        return true;
    }
}
