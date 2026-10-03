<?php

namespace App\Ads\Commands;

use App\Ads\Buyers\AssignmentService;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Sync\AdsSyncService;
use App\Ads\Sync\SyncAdAccount;
use App\Enums\UserRole;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use App\Models\MediaBuyer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Le Voile go-live in one run (owner, 2026-10-03): the three media buyers with their logins, the Meta
 * connection (token asked as a secret, never echoed) and each buyer's account from 2026-10-01.
 *
 * Safe to re-run: existing users keep their password (--reset-passwords to issue new ones), buyers and
 * assignments are matched, an existing Meta connection is reused. New logins' passwords are printed once.
 */
class SetupTeamCommand extends Command
{
    public const STARTS_ON = '2026-10-01';

    /** @var list<array{name: string, email: string, color: string, account: string, account_name: string}> */
    public const TEAM = [
        ['name' => 'Ahmed Gamal', 'email' => 'ahmed.gamal@arena.com', 'color' => '#2563eb', 'account' => 'act_1648538895706851', 'account_name' => 'Cloting'],
        ['name' => 'Bakinam', 'email' => 'bakinam@arena.com', 'color' => '#db2777', 'account' => 'act_6746411735418687', 'account_name' => 'Lv Main'],
        ['name' => 'Mostafa', 'email' => 'mostafa@arena.com', 'color' => '#059669', 'account' => 'act_950240346866068', 'account_name' => 'Lv Main 22'],
    ];

    protected $signature = 'ads:setup-team
        {--token= : Meta System User token (asked as a secret when omitted and no Meta connection exists)}
        {--reset-passwords : issue new passwords for the buyers\' logins}';

    protected $description = 'Le Voile: create the media buyers and their logins, connect Meta and assign each buyer their ad account';

    public function handle(AssignmentService $assignments, AdsSyncService $sync): int
    {
        $this->info('1/3 Media buyers and logins');
        $rows = [];
        $buyers = [];
        foreach (self::TEAM as $member) {
            [$user, $password] = $this->user($member);
            $buyers[$member['account']] = MediaBuyer::updateOrCreate(['user_id' => $user->id], ['name' => $member['name'], 'color' => $member['color'], 'is_active' => true]);
            $rows[] = [$member['name'], $user->email, $password ?? '(unchanged)'];
        }
        $this->table(['Buyer', 'Login', 'Password'], $rows);

        $this->info('2/3 Meta connection');
        if (! $this->connectMeta($sync)) {
            return self::FAILURE;
        }

        $this->info('3/3 Accounts from '.self::STARTS_ON);
        $start = CarbonImmutable::parse(self::STARTS_ON, 'Africa/Cairo')->startOfDay();
        $missing = 0;
        foreach (self::TEAM as $member) {
            $account = AdAccount::where('platform', 'meta')->where('external_id', $member['account'])->first();
            if ($account === null) {
                $this->warn("  {$member['account_name']} ({$member['account']}) is not on this token: give the System User access to it, then run again.");
                $missing++;

                continue;
            }
            try {
                $assignments->assign($account, $buyers[$member['account']], $start);
            } catch (ValidationException $e) {
                $this->warn("  {$account->name}: ".collect($e->errors())->flatten()->first());
                $missing++;

                continue;
            }
            $this->line("  {$account->name} -> {$member['name']}");
        }

        $this->newLine();
        $this->info($missing === 0
            ? 'Done. The 90-day history is syncing on the commercelong worker; the ads pages fill as it lands.'
            : "Done with {$missing} account(s) left to fix (see above).");

        return $missing === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{0: User, 1: ?string} the user and its new password (null when unchanged) */
    private function user(array $member): array
    {
        $user = User::firstOrNew(['email' => $member['email']]);
        $password = null;
        if (! $user->exists || $this->option('reset-passwords')) {
            $password = Str::password(12, symbols: false);
            $user->password = $password;
        }
        $user->fill(['name' => $member['name'], 'role' => UserRole::MediaBuyer, 'locale' => 'ar', 'is_active' => true])->save();

        return [$user, $password];
    }

    /** Reuses a working Meta connection, else creates one from the token and pulls its accounts. */
    private function connectMeta(AdsSyncService $sync): bool
    {
        $token = trim((string) $this->option('token'));
        $connection = AdPlatformConnection::where('platform', 'meta')->where('status', '!=', 'disabled')->orderBy('id')->first();

        if ($connection !== null && $token === '') {
            $this->line("  using «{$connection->name}»");
        } else {
            if ($token === '') {
                $token = trim((string) $this->secret('Meta System User token (ads_read on the three accounts)'));
            }
            if ($token === '') {
                $this->error('  No Meta token: run again with the token, or connect Meta from Ads -> Ad accounts.');

                return false;
            }
            $connection ??= new AdPlatformConnection(['platform' => 'meta', 'name' => 'Meta — Le Voile']);
            $connection->fill(['credentials' => ['access_token' => $token], 'status' => 'connected', 'last_error' => null])->save();
        }

        $known = AdAccount::pluck('id')->all();
        try {
            $count = $sync->syncAccounts($connection);
        } catch (AdsApiException $e) {
            $this->error('  Meta refused the token: '.AdsSyncService::scrub($e->getMessage()));

            return false;
        }
        $this->line("  {$count} ad account(s) on the token");

        $days = (int) config('crm.ads.backfill_days', 90);
        $connection->accounts()->where('is_active', true)->whereNotIn('id', $known)->pluck('id')
            ->each(fn (int $id) => SyncAdAccount::dispatch($id, $days, 'backfill'));

        return true;
    }
}
