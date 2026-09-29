<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\QueueSetting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The customer-service team of the handover queue (owner, 2026-09-29): فاطمة leads every shift,
 * seven moderators serve every platform. Safe to run again: an existing account (same email) is
 * kept with its password and only gets its role, platforms and active flag back. New accounts
 * get a random password, printed once so the owner can hand it over.
 */
class SetupQueueTeamCommand extends Command
{
    protected $signature = 'queue:setup-team
        {--domain=levoilestores.com : Email domain of the team accounts}
        {--reset-passwords : Give existing team accounts a new random password too}';

    protected $description = 'Create the queue team (فاطمة as leader of every shift + 7 moderators) and set her as shift leader';

    /** @var array<string, string> email local part => name */
    public const LEADER = ['fatma' => 'فاطمة'];

    /** @var array<string, string> */
    public const MODERATORS = [
        'zeinab' => 'زينب',
        'nermin' => 'نرمين',
        'manar' => 'منار',
        'randa' => 'راندة',
        'esraa' => 'إسراء',
        'engy' => 'إنجي',
        'hemaa' => 'هيماء',
    ];

    public const PLATFORMS = ['facebook', 'instagram', 'whatsapp', 'tiktok'];

    public function handle(): int
    {
        $domain = strtolower(trim((string) $this->option('domain'), " @\t"));
        $rows = [];

        DB::transaction(function () use ($domain, &$rows) {
            $leader = null;
            $moderatorIds = [];

            foreach (self::LEADER + self::MODERATORS as $local => $name) {
                $isLeader = array_key_exists($local, self::LEADER);
                [$user, $password] = $this->account("{$local}@{$domain}", $name, $isLeader ? UserRole::Supervisor : UserRole::Moderator);
                $rows[] = [$name, $user->email, $isLeader ? 'supervisor · الليدر' : 'moderator', $password ?? '(unchanged)'];

                if ($isLeader) {
                    $leader = $user;
                } else {
                    $moderatorIds[] = $user->id;
                }
            }

            $settings = QueueSetting::current();
            $shifts = array_map(fn (array $s) => array_replace($s, ['leader_user_id' => $leader->id]), $settings->shiftTemplates());

            $settings->update([
                'shifts' => $shifts,
                'default_roster' => [($shifts[0]['key'] ?? 'morning') => $moderatorIds],
            ]);
        });

        $this->table(['Name', 'Email', 'Role', 'Password'], $rows);
        $this->info('فاطمة is the leader of every shift. The queue itself stays as it was (Settings → Queue to switch it on).');

        return self::SUCCESS;
    }

    /** @return array{0: User, 1: ?string} the account and its new password (null = kept) */
    private function account(string $email, string $name, UserRole $role): array
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $password = ! $user->exists || $this->option('reset-passwords') ? Str::password(12, symbols: false) : null;

        $user->fill(array_filter([
            'name' => $user->exists ? null : $name,
            'role' => $role,
            'is_active' => true,
            'locale' => $user->exists ? null : 'ar',
            'password' => $password,
        ], fn ($v) => $v !== null))->save();

        foreach (self::PLATFORMS as $platform) {
            $user->userPlatforms()->firstOrCreate(['platform' => $platform]);
        }

        return [$user, $password];
    }
}
