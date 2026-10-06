<?php

namespace App\Ads\Alerts;

use Carbon\CarbonImmutable;

/**
 * T (target cost per result) per account (R 2, spec 7.1): owner value, else the median over the account's ads in the last
 * 60 complete days, else none (the rules then fall back to their absolute EGP floors and hard caps only).
 */
final class Targets
{
    public const WINDOW_DAYS = 60;

    public const MIN_ADS = 3;

    public const MIN_AD_SPEND = 100.0;

    /** @var array<string, array<string, mixed>> */
    private array $memo = [];

    public function __construct(
        private readonly RuleSettings $settings,
        private readonly AlertData $data,
        private readonly ChatSignals $chats,
    ) {}

    /** @return array{cpp: ?float, cpp_source: string, cpo: ?float, cpo_source: string, cpc: ?float} */
    public function forAccount(int $accountId, CarbonImmutable $today): array
    {
        $key = $accountId.'|'.$today->toDateString();
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $in = $this->settings->inputsFor($accountId)['values'];
        $from = $today->subDays(self::WINDOW_DAYS)->toDateString();
        $to = $today->subDay()->toDateString();
        $ads = $this->data->accountAds($accountId);
        $sums = $this->data->sums($ads->keys()->all(), $from, $to);
        $orders = $this->data->realOrders($ads->keys()->all(), $from, $to);

        $families = [];
        foreach ($ads as $id => $ad) {
            $families[$id] = Family::of($ad->campaign?->objective, $sums[$id]['msg_conversations'] ?? 0);
        }
        $msgIds = array_keys(array_filter($families, fn (string $f) => $f === Family::MESSAGES));
        $chat = $this->chats->forAds($msgIds, $from, $to);

        $cpp = $cpo = $cpc = [];
        foreach ($ads as $id => $ad) {
            $s = $sums[$id] ?? null;
            if ($s === null || $s['spend'] < self::MIN_AD_SPEND) {
                continue;
            }
            if ($families[$id] === Family::SALES && ($p = max($s['purchases'], $orders[$id]['count'] ?? 0)) > 0) {
                $cpp[] = $s['spend'] / $p;
            }
            if ($families[$id] === Family::MESSAGES) {
                if (($o = (int) ($chat[$id]['orders'] ?? 0)) > 0) {
                    $cpo[] = $s['spend'] / $o;
                }
                if (($c = max($s['msg_conversations'], (int) ($chat[$id]['chats'] ?? 0))) > 0) {
                    $cpc[] = $s['spend'] / $c;
                }
            }
        }

        $median = fn (array $xs): ?float => count($xs) >= self::MIN_ADS ? round((float) Stats::median($xs), 2) : null;
        $pick = function (?float $owner, array $xs) use ($median): array {
            if ($owner !== null) {
                return [$owner, 'owner'];
            }
            $m = $median($xs);

            return $m !== null ? [$m, 'median'] : [null, 'none'];
        };
        [$cppValue, $cppSource] = $pick($in['target_cpp'], $cpp);
        [$cpoValue, $cpoSource] = $pick($in['target_cpo'], $cpo);

        return $this->memo[$key] = [
            'cpp' => $cppValue, 'cpp_source' => $cppSource, 'cpo' => $cpoValue, 'cpo_source' => $cpoSource, 'cpc' => $median($cpc),
        ];
    }
}
