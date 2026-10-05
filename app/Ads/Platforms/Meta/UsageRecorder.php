<?php

namespace App\Ads\Platforms\Meta;

use App\Models\AdAccount;
use App\Models\AdsApiUsage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Throwable;

/** Stores the Meta usage headers of every Graph response (2xx and errors). Never throws. */
class UsageRecorder
{
    /** @var array<string, int|null> external id => ad_accounts.id, kept for the life of this (singleton) instance */
    private array $accountIds = [];

    private const HEADERS = ['x-business-use-case-usage', 'x-ad-account-usage'];

    public function record(Response $r, string $path): void
    {
        try {
            $now = now();
            foreach (self::HEADERS as $name) {
                $raw = $r->header($name);
                if ($raw === '') {
                    continue;
                }
                $decoded = json_decode($raw, true);
                if (! is_array($decoded)) {
                    continue;
                }
                $rows = $name === 'x-business-use-case-usage'
                    ? $this->businessRows($decoded, $path)
                    : $this->accountRows($decoded, $path);
                foreach ($rows as $row) {
                    AdsApiUsage::create($row + ['header' => $name, 'recorded_at' => $now]);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return array{max_pct: float, regain_minutes: int, recorded_at: CarbonImmutable}|null the busiest reading of the last $minutes */
    public function busiest(?int $adAccountId, int $minutes = 15): ?array
    {
        $row = AdsApiUsage::query()
            ->where('recorded_at', '>=', now()->subMinutes($minutes))
            ->when($adAccountId !== null, fn ($q) => $q->where('ad_account_id', $adAccountId))
            ->orderByDesc('max_pct')->orderByDesc('recorded_at')->first();

        return $row === null ? null : [
            'max_pct' => (float) $row->max_pct,
            'regain_minutes' => (int) $row->regain_minutes,
            'recorded_at' => CarbonImmutable::instance($row->recorded_at),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function businessRows(array $decoded, string $path): array
    {
        $out = [];
        foreach ($decoded as $key => $entries) {
            foreach ((array) $entries as $e) {
                if (! is_array($e)) {
                    continue;
                }
                $count = $this->pct($e['call_count'] ?? 0);
                $time = $this->pct($e['total_time'] ?? 0);
                $cpu = $this->pct($e['total_cputime'] ?? 0);
                $out[] = [
                    'ad_account_id' => $this->accountId($path, (string) $key),
                    'business_id' => mb_substr((string) $key, 0, 40),
                    'usage_type' => isset($e['type']) ? mb_substr((string) $e['type'], 0, 40) : null,
                    'call_count' => $count, 'total_time' => $time, 'total_cputime' => $cpu,
                    'max_pct' => max($count, $time, $cpu),
                    'regain_minutes' => max(0, (int) ($e['estimated_time_to_regain_access'] ?? 0)),
                ];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function accountRows(array $decoded, string $path): array
    {
        if (! array_key_exists('acc_id_util_pct', $decoded)) {
            return [];
        }
        $pct = $this->pct($decoded['acc_id_util_pct']);

        return [[
            'ad_account_id' => $this->accountId($path, ''),
            'call_count' => $pct, 'max_pct' => $pct,
            'regain_minutes' => max(0, (int) ($decoded['reset_time_duration'] ?? 0)),
        ]];
    }

    private function pct(mixed $v): float
    {
        return min(999.99, max(0.0, (float) $v));
    }

    /** ad_accounts.id of a Meta act_<id>, or null when the account is not stored. */
    public function accountIdFor(string $actExternalId): ?int
    {
        return $this->accountId($actExternalId, '');
    }

    private function accountId(string $path, string $headerKey): ?int
    {
        foreach ([$path, $headerKey] as $source) {
            if (preg_match('/\bact_(\d+)/', $source, $m)) {
                $ext = 'act_'.$m[1];
                $this->accountIds[$ext] ??= AdAccount::where('platform', 'meta')->where('external_id', $ext)->value('id');
                if ($this->accountIds[$ext] !== null) {
                    return (int) $this->accountIds[$ext];
                }
            }
        }

        return null;
    }
}
