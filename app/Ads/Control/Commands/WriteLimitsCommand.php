<?php

namespace App\Ads\Control\Commands;

use App\Ads\Audit\AdsAudit;
use App\Ads\Control\Write\WriteLimits;
use App\Models\AdAccount;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * The Run guard limits (B3): show them, or set / clear an override globally, for one account or for one user. Server
 * access only; every change is one audit row with the scope's values before and after.
 */
class WriteLimitsCommand extends Command
{
    protected $signature = 'ads:write-limits
        {--list : Show the effective limits (default)}
        {--set=* : key=value, e.g. max_daily_budget_minor=3000000 (integers >= 0; cap_currency = 3 uppercase letters)}
        {--clear=* : Remove the key from the scope (falls back to the next level)}
        {--account= : Scope: an ad account id or its external id (act_...)}
        {--user= : Scope: a user id or email}';

    protected $description = 'Show or change the Run guard limits (budget cap, activation caps, restart lock)';

    public function handle(WriteLimits $limits): int
    {
        $set = (array) $this->option('set');
        $clear = (array) $this->option('clear');
        $accountOpt = $this->option('account');
        $userOpt = $this->option('user');

        if ($accountOpt !== null && $userOpt !== null) {
            $this->error('Use either --account or --user.');

            return self::FAILURE;
        }

        if ($set !== [] || $clear !== []) {
            $changes = $this->parse($set);
            if ($changes === null) {
                return self::FAILURE;
            }
            foreach ($clear as $key) {
                if (! array_key_exists($key, WriteLimits::KEYS)) {
                    $this->error("Unknown limit {$key}. Known: ".implode(', ', array_keys(WriteLimits::KEYS)).'.');

                    return self::FAILURE;
                }
            }

            [$scope, $subject] = $this->scope($accountOpt, $userOpt);
            if ($scope === null) {
                return self::FAILURE;
            }
            // The per-user cap is resolved from the user level only, the per-account cap from the account level only.
            $wrong = match ($scope) {
                'account' => 'activations_per_user_day',
                'user' => 'activations_per_account_day',
                default => null,
            };
            if ($wrong !== null && array_key_exists($wrong, $changes)) {
                $this->error("{$wrong} cannot be set for one {$scope}; set it globally or on the ".($scope === 'account' ? 'user' : 'account').'.');

                return self::FAILURE;
            }

            $stored = $limits->stored();
            $before = $this->scopeValues($stored, $scope, $subject);
            $after = array_diff_key(array_merge($before, $changes), array_flip($clear));
            if ($after !== $before) {
                $stored = $this->withScopeValues($stored, $scope, $subject, $after);
                $limits->store($stored);
                AdsAudit::record('settings.write_limits_changed', $subject, ['limits' => $before], ['limits' => $after],
                    array_filter(['scope' => $scope, 'user_id' => $subject instanceof User ? $subject->id : null], fn ($v) => $v !== null));
                $this->info('Limits saved for '.$this->label($scope, $subject).'.');
            } else {
                $this->line('Nothing changed.');
            }
        }

        $this->show($limits);

        return self::SUCCESS;
    }

    /** @return array<string, int|string>|null */
    private function parse(array $set): ?array
    {
        $out = [];
        foreach ($set as $pair) {
            if (! str_contains((string) $pair, '=')) {
                $this->error("Use key=value ({$pair}).");

                return null;
            }
            [$key, $value] = array_map('trim', explode('=', (string) $pair, 2));
            $kind = WriteLimits::KEYS[$key] ?? null;
            if ($kind === null) {
                $this->error("Unknown limit {$key}. Known: ".implode(', ', array_keys(WriteLimits::KEYS)).'.');

                return null;
            }
            if ($kind === 'int') {
                if (! ctype_digit($value)) {
                    $this->error("{$key} must be a whole number of 0 or more.");

                    return null;
                }
                $out[$key] = (int) $value;
            } else {
                if (preg_match('/^[A-Z]{3}$/', $value) !== 1) {
                    $this->error("{$key} must be 3 uppercase letters (e.g. EGP).");

                    return null;
                }
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @return array{0: ?string, 1: ?Model} */
    private function scope(?string $account, ?string $user): array
    {
        if ($account !== null) {
            $needle = trim($account);
            $a = ctype_digit($needle) ? AdAccount::query()->find((int) $needle) : null;
            $a ??= AdAccount::query()->where('external_id', $needle)->first();
            if ($a === null) {
                $this->error("No ad account matches {$account}.");

                return [null, null];
            }

            return ['account', $a];
        }
        if ($user !== null) {
            $needle = trim($user);
            $u = ctype_digit($needle) ? User::query()->find((int) $needle) : User::query()->where('email', $needle)->first();
            if ($u === null) {
                $this->error("No user matches {$user}.");

                return [null, null];
            }

            return ['user', $u];
        }

        return ['global', null];
    }

    private function scopeValues(array $stored, string $scope, ?Model $subject): array
    {
        return match ($scope) {
            'account' => $stored['accounts'][(string) $subject->getKey()] ?? [],
            'user' => $stored['users'][(string) $subject->getKey()] ?? [],
            default => $stored['global'],
        };
    }

    private function withScopeValues(array $stored, string $scope, ?Model $subject, array $values): array
    {
        match ($scope) {
            'account' => $stored['accounts'][(string) $subject->getKey()] = $values,
            'user' => $stored['users'][(string) $subject->getKey()] = $values,
            default => $stored['global'] = $values,
        };

        return $stored;
    }

    private function label(string $scope, ?Model $subject): string
    {
        return match (true) {
            $subject instanceof AdAccount => "account {$subject->id} ({$subject->name})",
            $subject instanceof User => "user {$subject->id} ({$subject->email})",
            default => 'everyone (global)',
        };
    }

    private function show(WriteLimits $limits): void
    {
        $effective = $limits->for(null, null);
        $this->line('Effective limits (global):');
        $this->table(['limit', 'value'], $this->rows($effective));

        $stored = $limits->stored();
        foreach ($stored['accounts'] as $id => $values) {
            $a = AdAccount::query()->find((int) $id);
            $this->line('Account override: '.($a !== null ? "{$a->id} {$a->name} ({$a->external_id})" : "#{$id} (deleted)"));
            $this->table(['limit', 'value'], $this->rows($a !== null ? array_intersect_key($limits->for(null, $a), $values) : $values, $effective['cap_currency']));
        }
        foreach ($stored['users'] as $id => $values) {
            $u = User::query()->find((int) $id);
            $this->line('User override: '.($u !== null ? "{$u->id} {$u->email}" : "#{$id} (deleted)"));
            $this->table(['limit', 'value'], $this->rows($u !== null ? array_intersect_key($limits->for($u, null), $values) : $values, $effective['cap_currency']));
        }
    }

    /** @return list<array{0: string, 1: string}> */
    private function rows(array $values, ?string $currency = null): array
    {
        $currency = (string) ($values['cap_currency'] ?? $currency ?? 'EGP');
        $rows = [];
        foreach ($values as $key => $value) {
            $rows[] = [$key, $key === 'max_daily_budget_minor' ? WriteLimits::money((int) $value, $currency) : (string) $value];
        }

        return $rows;
    }
}
