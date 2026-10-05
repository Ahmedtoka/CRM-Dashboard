<?php

namespace App\Ads\Control\Commands;

use App\Ads\Audit\AdsAudit;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Who holds Ads authority (users.ads_authority, D4): Run and Stop at campaign and ad-set level. Server access only until
 * the re-authentication step (B9) ships; no HTTP route changes the flag.
 */
class AdsAuthorityCommand extends Command
{
    protected $signature = 'ads:authority
        {--list : Show the holders (default)}
        {--grant= : Email or id of an admin or supervisor to grant Ads authority}
        {--revoke= : Email or id of a holder to revoke}';

    protected $description = 'Show, grant or revoke Ads authority (campaign / ad-set Run and Stop)';

    /** A buyer's power is assignment-scoped; Ads authority is for the people who oversee every account. */
    private const GRANTABLE = [UserRole::Admin, UserRole::Supervisor];

    public function handle(): int
    {
        $grant = $this->option('grant');
        $revoke = $this->option('revoke');
        if ($grant !== null && $revoke !== null) {
            $this->error('Use either --grant or --revoke.');

            return self::FAILURE;
        }

        if ($grant !== null || $revoke !== null) {
            $user = $this->findUser((string) ($grant ?? $revoke));
            if ($user === null) {
                $this->error('No user matches '.($grant ?? $revoke).'.');

                return self::FAILURE;
            }
            $ok = $grant !== null ? $this->grant($user) : $this->revoke($user);
            if (! $ok) {
                return self::FAILURE;
            }
        }

        $this->show();

        return self::SUCCESS;
    }

    private function grant(User $user): bool
    {
        if (! in_array($user->role, self::GRANTABLE, true)) {
            $this->error("{$user->email} is a ".($user->role?->value ?? 'user').'; only an admin or a supervisor can hold Ads authority.');

            return false;
        }
        if ($user->ads_authority) {
            $this->line("{$user->email} already holds Ads authority.");

            return true;
        }
        $this->change($user, true);
        $this->info("Granted Ads authority to {$user->email}.");

        return true;
    }

    private function revoke(User $user): bool
    {
        return DB::transaction(function () use ($user) {
            $holders = User::query()->where('ads_authority', true)->where('is_active', true)->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (! $user->ads_authority) {
                $this->line("{$user->email} does not hold Ads authority.");

                return true;
            }
            if ($user->is_active && $holders === [(int) $user->id]) {
                $this->error("{$user->email} is the last active holder; grant someone else first.");

                return false;
            }
            $this->change($user, false);
            $this->info("Revoked Ads authority from {$user->email}.");

            return true;
        });
    }

    private function change(User $user, bool $value): void
    {
        $user->forceFill(['ads_authority' => $value])->save();
        AdsAudit::record('user.ads_authority_changed', $user, ['ads_authority' => ! $value], ['ads_authority' => $value]);
    }

    private function findUser(string $needle): ?User
    {
        $needle = trim($needle);

        return ctype_digit($needle) ? User::query()->find((int) $needle) : User::query()->where('email', $needle)->first();
    }

    private function show(): void
    {
        $rows = User::query()->where('ads_authority', true)->orderBy('id')->get()
            ->map(fn (User $u) => [$u->id, $u->name, $u->email, $u->role?->value, $u->is_active ? 'yes' : 'no'])->all();
        $this->table(['id', 'name', 'email', 'role', 'active'], $rows);
    }
}
