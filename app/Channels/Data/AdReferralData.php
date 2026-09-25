<?php

namespace App\Channels\Data;

/**
 * Where a Messenger / Instagram conversation came from (2026-09-25): a Click-to-Messenger ad
 * (`source: ADS`, with `ad_id` and the ad's own title/picture in `ads_context_data`), an m.me
 * or ig.me link with a `ref`, or a QR code. Carried on the inbound message it arrived with,
 * and kept on the conversation as its ad attribution.
 */
final readonly class AdReferralData
{
    public function __construct(
        public string $source,
        public ?string $type = null,
        public ?string $ref = null,
        public ?string $adId = null,
        public ?string $adTitle = null,
        public ?string $postId = null,
        public ?string $photoUrl = null,
        public ?string $productId = null,
    ) {}

    /** @param  array<string, mixed>  $referral  Meta's `referral` object */
    public static function fromMeta(array $referral): ?self
    {
        $source = strtoupper(trim((string) ($referral['source'] ?? '')));
        $context = (array) ($referral['ads_context_data'] ?? []);
        $adId = isset($referral['ad_id']) && $referral['ad_id'] !== '' ? (string) $referral['ad_id'] : null;

        if ($source === '' && $adId === null && blank($referral['ref'] ?? null)) {
            return null;
        }

        return new self(
            source: $source !== '' ? $source : ($adId !== null ? 'ADS' : 'UNKNOWN'),
            type: isset($referral['type']) ? (string) $referral['type'] : null,
            ref: filled($referral['ref'] ?? null) ? mb_substr((string) $referral['ref'], 0, 190) : null,
            adId: $adId,
            adTitle: filled($context['ad_title'] ?? null) ? mb_substr((string) $context['ad_title'], 0, 190) : null,
            postId: filled($context['post_id'] ?? null) ? (string) $context['post_id'] : null,
            photoUrl: filled($context['photo_url'] ?? null) ? mb_substr((string) $context['photo_url'], 0, 500) : null,
            productId: filled($context['product_id'] ?? null) ? (string) $context['product_id'] : null,
        );
    }

    public function isAd(): bool
    {
        return $this->adId !== null || $this->source === 'ADS';
    }
}
