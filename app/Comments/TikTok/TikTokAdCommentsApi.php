<?php

namespace App\Comments\TikTok;

use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\TikTok\TikTokApi;
use App\Models\AdAccount;

/**
 * The Marketing API ad comments endpoints (E1, documented in docs/round-2026-10-17/tiktok.md §1ب), on the ad
 * account's own Marketing token (no TikTok account login needed):
 *   GET  comment/list/           search_field=ADGROUP_ID, date-only start/end, page_size ≤ 100
 *   POST comment/post/           a REPLY to a first-level comment, from the ad's identity
 *   POST comment/status/update/  HIDDEN | PUBLIC
 */
class TikTokAdCommentsApi
{
    private const PAGE_SIZE = 100;

    public function __construct(private readonly TikTokApi $api) {}

    /**
     * Comments of one ad group created between two dates (inclusive, YYYY-MM-DD).
     *
     * @return list<array<string, mixed>>
     */
    public function list(AdAccount $a, string $adgroupId, string $from, string $to): array
    {
        return $this->api->paginate($this->token($a), 'comment/list/', [
            'advertiser_id' => $this->id((string) $a->external_id),
            'search_field' => 'ADGROUP_ID',
            'search_value' => $this->id($adgroupId),
            'start_time' => $from,
            'end_time' => $to,
        ], self::PAGE_SIZE);
    }

    /**
     * A public reply under a first-level comment, posted as the ad's identity.
     *
     * @param  array{ad_id?: ?string, tiktok_item_id?: ?string, identity_id?: ?string, identity_type?: ?string}  $meta  the post's platform_meta
     * @return string the new reply's comment id ('' when TikTok names none)
     */
    public function reply(AdAccount $a, array $meta, string $commentId, string $text): string
    {
        foreach (['ad_id', 'tiktok_item_id', 'identity_id', 'identity_type'] as $key) {
            if (empty($meta[$key])) {
                throw new AdsApiException("TikTok needs the ad's {$key} to reply; it is not known for this ad yet.");
            }
        }

        $data = $this->api->post($this->token($a), 'comment/post/', [
            'advertiser_id' => $this->id((string) $a->external_id),
            'ad_id' => $this->id((string) $meta['ad_id']),
            'tiktok_item_id' => (string) $meta['tiktok_item_id'],
            'comment_id' => $this->id($commentId),
            'comment_type' => 'REPLY',
            'text' => $text,
            'identity_type' => (string) $meta['identity_type'],
            'identity_id' => (string) $meta['identity_id'],
        ]);

        return (string) ($data['comment_id'] ?? $data['comment']['comment_id'] ?? $data['list'][0]['comment_id'] ?? '');
    }

    public function setHidden(AdAccount $a, string $commentId, bool $hidden): void
    {
        $this->api->post($this->token($a), 'comment/status/update/', [
            'advertiser_id' => $this->id((string) $a->external_id),
            'comment_ids' => [$this->id($commentId)],
            'operation' => $hidden ? 'HIDDEN' : 'PUBLIC',
        ]);
    }

    private function token(AdAccount $a): string
    {
        $token = $a->connection?->credentials['access_token'] ?? null;
        if (! $token) {
            throw new AdsApiException('TikTok access token is missing.');
        }

        return (string) $token;
    }

    /** Ids go into request bodies and filters, so only digits are accepted. */
    private function id(string $id): string
    {
        if ($id === '' || ! ctype_digit($id)) {
            throw new AdsApiException('Invalid TikTok id.');
        }

        return $id;
    }
}
