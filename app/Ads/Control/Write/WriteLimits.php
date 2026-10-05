<?php

namespace App\Ads\Control\Write;

use App\Ads\AdsSettings;
use App\Models\AdAccount;
use App\Models\User;

/**
 * The Run guard's limits (B3), never hardcoded: config defaults (crm.ads.write.limits), overridden at runtime by the
 * ads_settings key `write_limits` = {global:{…}, accounts:{<id>:{…}}, users:{<id>:{…}}}, changed only through
 * `ads:write-limits` (audited). Resolution: user → account → global → config. The per-user activation cap never comes
 * from an account override and the per-account cap never from a user override.
 *
 * Money is integer minor units. ASSUMPTION: the cap currency has 2 decimals (EGP); money() is the only place a minor
 * amount is turned into a major one, for display only.
 */
final class WriteLimits
{
    public const SETTING = 'write_limits';

    /** Every key and whether it is an integer (else a 3-letter currency code). */
    public const KEYS = [
        'max_daily_budget_minor' => 'int',
        'cap_currency' => 'currency',
        'activations_per_user_day' => 'int',
        'activations_per_account_day' => 'int',
        'restart_lock_days' => 'int',
        'learning_note_days' => 'int',
    ];

    private const DEFAULTS = [
        'max_daily_budget_minor' => 2000000,
        'cap_currency' => 'EGP',
        'activations_per_user_day' => 20,
        'activations_per_account_day' => 30,
        'restart_lock_days' => 7,
        'learning_note_days' => 7,
    ];

    public function __construct(private readonly AdsSettings $settings) {}

    /**
     * @return array{max_daily_budget_minor: int, cap_currency: string, activations_per_user_day: int, activations_per_account_day: int, restart_lock_days: int, learning_note_days: int}
     */
    public function for(?User $u, ?AdAccount $a): array
    {
        $stored = $this->stored();
        $global = $stored['global'];
        $account = $a?->id !== null ? ($stored['accounts'][(string) $a->id] ?? []) : [];
        $user = $u?->id !== null ? ($stored['users'][(string) $u->id] ?? []) : [];

        $out = [];
        foreach (self::KEYS as $key => $kind) {
            $layers = match ($key) {
                'activations_per_user_day' => [$user, $global],
                'activations_per_account_day' => [$account, $global],
                default => [$user, $account, $global],
            };
            $value = null;
            foreach ($layers as $layer) {
                if (array_key_exists($key, $layer)) {
                    $value = $layer[$key];
                    break;
                }
            }
            $value ??= config('crm.ads.write.limits.'.$key, self::DEFAULTS[$key]);
            $out[$key] = $kind === 'int' ? max(0, (int) $value) : strtoupper((string) $value);
        }

        /** @var array{max_daily_budget_minor: int, cap_currency: string, activations_per_user_day: int, activations_per_account_day: int, restart_lock_days: int, learning_note_days: int} $out */
        return $out;
    }

    /**
     * The stored overrides, normalised.
     *
     * @return array{global: array<string, mixed>, accounts: array<string, array<string, mixed>>, users: array<string, array<string, mixed>>}
     */
    public function stored(): array
    {
        $raw = $this->settings->get(self::SETTING, []);
        $raw = is_array($raw) ? $raw : [];

        return [
            'global' => is_array($raw['global'] ?? null) ? $raw['global'] : [],
            'accounts' => is_array($raw['accounts'] ?? null) ? $raw['accounts'] : [],
            'users' => is_array($raw['users'] ?? null) ? $raw['users'] : [],
        ];
    }

    /** @param  array{global: array<string, mixed>, accounts: array<string, array<string, mixed>>, users: array<string, array<string, mixed>>}  $stored */
    public function store(array $stored): void
    {
        $stored['accounts'] = array_filter($stored['accounts']);
        $stored['users'] = array_filter($stored['users']);
        $this->settings->set(self::SETTING, $stored);
    }

    /** `<minor> minor (<CUR> <major>)`, e.g. `50000 minor (EGP 500.00)`. Display only (2 decimals, ASSUMPTION). */
    public static function money(int $minor, string $currency): string
    {
        return $minor.' minor ('.$currency.' '.number_format($minor / 100, 2).')';
    }

    /** Major amount only, e.g. `20,000.00` (display only). */
    public static function major(int $minor): string
    {
        return number_format($minor / 100, 2);
    }
}
