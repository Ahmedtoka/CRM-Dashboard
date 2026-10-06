<?php

namespace App\Ads\Alerts;

use App\Models\Ad;

/** What one rule found on one entity in one evaluation; AlertStore turns it into an ads_alerts row. */
final readonly class Finding
{
    /**
     * @param  array<string, scalar|null>  $params  sentence parameters (ads.alerts.rules.{sentenceKey})
     * @param  array<string, mixed>  $evidence  numbers behind the sentence (window, inputs, thresholds)
     */
    public function __construct(
        public string $ruleId,
        public string $severity,
        public string $action,
        public string $entityLevel,
        public int $entityId,
        public ?int $accountId,
        public ?int $adId,
        public ?int $productId,
        public ?string $family,
        public float $moneyAtRiskPerDay,
        public string $sentenceKey,
        public array $params,
        public array $evidence,
        public string $kind = 'alert',
    ) {}

    public function fingerprint(): string
    {
        return "{$this->kind}:{$this->ruleId}:{$this->entityLevel}:{$this->entityId}";
    }

    /** @param  array<string, mixed>  $changes  constructor argument names */
    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /**
     * @param  array<string, scalar|null>  $params
     * @param  array<string, mixed>  $evidence
     */
    public static function forAd(string $ruleId, Ad $ad, ?string $family, string $severity, string $action, float $money, string $sentenceKey, array $params, array $evidence, ?int $productId = null, string $kind = 'alert'): self
    {
        return new self($ruleId, $severity, $action, 'ad', (int) $ad->id, (int) $ad->ad_account_id, (int) $ad->id, $productId, $family, max(0.0, $money), $sentenceKey, $params, $evidence, $kind);
    }
}
