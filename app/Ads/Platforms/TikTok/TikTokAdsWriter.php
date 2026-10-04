<?php

namespace App\Ads\Platforms\TikTok;

use App\Ads\Platforms\AdPlatformWriter;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Data\AdDraft;
use App\Ads\Platforms\Data\CampaignNode;
use App\Ads\Platforms\Data\Identity;
use App\Ads\Platforms\Data\MediaRef;
use App\Models\AdAccount;
use App\Models\AdMaterialFile;
use Illuminate\Support\Facades\Storage;

/**
 * TikTok Business API v1.3 write side. Ads are always created with operation_status=DISABLE (paused);
 * status values are ENABLE / DISABLE. TikTok has no url_tags, so the UTM query goes into landing_page_url.
 *
 * Identity mapping: TikTok has no page, so Identity(pageId: identity_id, pageName: display_name,
 * instagramId: identity_type), where identity_type (for example CUSTOMIZED_USER, TT_USER, BC_AUTH_TT) is the
 * second half of the pair ad/create needs.
 */
class TikTokAdsWriter implements AdPlatformWriter
{
    private const DEFAULT_UTM = 'utm_source=tiktok&utm_medium=paid&utm_campaign=__CAMPAIGN_NAME__&utm_content=__CID__';

    private const CTA = ['SHOP_NOW' => 'SHOP_NOW', 'LEARN_MORE' => 'LEARN_MORE', 'ORDER_NOW' => 'ORDER_NOW', 'SEND_MESSAGE' => 'CONTACT_US'];

    private const LEVELS = [
        'campaign' => ['campaign/status/update/', 'campaign_ids'],
        'adset' => ['adgroup/status/update/', 'adgroup_ids'],
        'ad' => ['ad/status/update/', 'ad_ids'],
    ];

    public function __construct(private readonly TikTokApi $api) {}

    public function liveCampaigns(AdAccount $a): array
    {
        $token = $this->token($a);
        $advertiser = $this->advertiserId($a);

        $campaigns = $this->api->paginate($token, 'campaign/get/', [
            'advertiser_id' => $advertiser,
            'fields' => json_encode(['campaign_id', 'campaign_name', 'operation_status', 'objective_type']),
            'filtering' => json_encode(['primary_status' => 'STATUS_NOT_DELETE']),
        ]);
        $campaigns = array_values(array_filter($campaigns, fn ($r) => ! empty($r['campaign_id'])));

        $groups = [];
        foreach (array_chunk(array_map(fn ($r) => (string) $r['campaign_id'], $campaigns), 100) as $ids) {
            $rows = $this->api->paginate($token, 'adgroup/get/', [
                'advertiser_id' => $advertiser,
                'fields' => json_encode(['adgroup_id', 'adgroup_name', 'campaign_id', 'operation_status']),
                'filtering' => json_encode(['campaign_ids' => $ids, 'primary_status' => 'STATUS_NOT_DELETE']),
            ]);
            foreach ($rows as $g) {
                if (! empty($g['adgroup_id']) && ! empty($g['campaign_id'])) {
                    $groups[(string) $g['campaign_id']][] = [
                        'id' => (string) $g['adgroup_id'],
                        'name' => (string) ($g['adgroup_name'] ?? $g['adgroup_id']),
                        'status' => $this->status($g['operation_status'] ?? null),
                    ];
                }
            }
        }

        return array_map(fn (array $r) => new CampaignNode(
            id: (string) $r['campaign_id'],
            name: (string) ($r['campaign_name'] ?? $r['campaign_id']),
            status: $this->status($r['operation_status'] ?? null),
            objective: $r['objective_type'] ?? null,
            adSets: $groups[(string) $r['campaign_id']] ?? [],
        ), $campaigns);
    }

    public function identities(AdAccount $a): array
    {
        $data = $this->api->get($this->token($a), 'identity/get/', ['advertiser_id' => $this->advertiserId($a)]);
        $rows = $data['identity_list'] ?? $data['list'] ?? [];

        return array_values(array_map(fn (array $r) => new Identity(
            pageId: (string) $r['identity_id'],
            pageName: (string) ($r['display_name'] ?? $r['identity_id']),
            instagramId: isset($r['identity_type']) ? (string) $r['identity_type'] : null,
        ), array_filter($rows, fn ($r) => ! empty($r['identity_id']))));
    }

    public function uploadMedia(AdAccount $a, AdMaterialFile $file): MediaRef
    {
        $video = str_starts_with((string) $file->mime, 'video/');
        $disk = Storage::disk($file->disk);
        $stream = $disk->readStream($file->path);
        if (! is_resource($stream)) {
            throw new AdsApiException('The media file cannot be read.');
        }

        // Once handed to the HTTP client the handle belongs to its stream wrapper, which closes it.
        try {
            // The md5 is computed in a streaming pass; the same handle is then rewound and streamed as the part.
            $hash = hash_init('md5');
            hash_update_stream($hash, $stream);
            $signature = hash_final($hash);
            if (rewind($stream) === false) {
                throw new AdsApiException('The media file cannot be read.');
            }

            $kind = $video ? 'video' : 'image';
            $data = $this->api->postMultipart($this->token($a), "file/{$kind}/ad/upload/", [
                'advertiser_id' => $this->advertiserId($a),
                'upload_type' => 'UPLOAD_BY_FILE',
                $kind.'_signature' => $signature,
            ], $kind.'_file', $stream, $file->original_name ?: ($video ? 'video.mp4' : 'image.jpg'));
        } catch (\Throwable $e) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw $e;
        }

        // The video endpoint answers with a list, the image endpoint with an object.
        $row = isset($data[0]) && is_array($data[0]) ? $data[0] : $data;
        $id = $row[$kind.'_id'] ?? null;
        if (! $id) {
            throw new AdsApiException("TikTok did not return a {$kind} id.");
        }

        return new MediaRef($kind, (string) $id, ! $video);
    }

    public function mediaReady(AdAccount $a, MediaRef $ref): bool
    {
        if ($ref->kind !== 'video') {
            return true;
        }
        $data = $this->api->get($this->token($a), 'file/video/ad/info/', [
            'advertiser_id' => $this->advertiserId($a),
            'video_ids' => json_encode([$ref->id]),
        ]);
        foreach ($data['list'] ?? [] as $v) {
            if ((string) ($v['video_id'] ?? '') === $ref->id && (! empty($v['preview_url']) || ! empty($v['video_cover_url']))) {
                return true;
            }
        }

        return false;
    }

    public function createPausedAd(AdAccount $a, AdDraft $draft): string
    {
        if ($draft->identity->instagramId === null || $draft->identity->instagramId === '') {
            throw new AdsApiException('TikTok identity type is missing.');
        }
        $creative = [
            'ad_name' => $draft->name,
            'identity_id' => $draft->identity->pageId,
            'identity_type' => $draft->identity->instagramId,
            'operation_status' => 'DISABLE',
            'ad_format' => $draft->media->kind === 'video' ? 'SINGLE_VIDEO' : 'SINGLE_IMAGE',
            'ad_text' => $draft->primaryText,
            'call_to_action' => self::CTA[$draft->cta] ?? $draft->cta,
            'landing_page_url' => $this->landingUrl($draft),
        ];
        if ($draft->media->kind === 'video') {
            $creative['video_id'] = $draft->media->id;
        } else {
            $creative['image_ids'] = [$draft->media->id];
        }

        $data = $this->api->post($this->token($a), 'ad/create/', [
            'advertiser_id' => $this->advertiserId($a),
            'adgroup_id' => $this->numericId($draft->adSetId),
            'operation_status' => 'DISABLE',
            'creatives' => [$creative],
        ]);

        $id = $data['ad_ids'][0] ?? null;
        if (! $id) {
            throw new AdsApiException('TikTok did not return an ad id.');
        }

        // It is not verified that TikTok honours operation_status on ad/create; force DISABLE so the ad can never spend.
        $ids = array_map('strval', array_values(array_filter((array) $data['ad_ids'])));
        try {
            $this->api->post($this->token($a), 'ad/status/update/', [
                'advertiser_id' => $this->advertiserId($a),
                'ad_ids' => $ids,
                'operation_status' => 'DISABLE',
            ]);
        } catch (AdsApiException $e) {
            throw new AdsApiException('TikTok created ad '.implode(',', $ids).' but could not confirm it is paused: '.$e->getMessage(), 0, $e);
        }

        return (string) $id;
    }

    public function setStatus(AdAccount $a, string $level, string $externalId, string $status): void
    {
        if (! isset(self::LEVELS[$level])) {
            throw new AdsApiException('Unknown ad level: '.$level);
        }
        [$path, $key] = self::LEVELS[$level];

        $this->api->post($this->token($a), $path, [
            'advertiser_id' => $this->advertiserId($a),
            $key => [$this->numericId($externalId)],
            'operation_status' => strtolower($status) === 'active' ? 'ENABLE' : 'DISABLE',
        ]);
    }

    /** The draft's link with the UTM query appended (url_tags does not exist on TikTok). */
    private function landingUrl(AdDraft $draft): string
    {
        $utm = trim($draft->urlTags) !== '' ? ltrim($draft->urlTags, '?&') : self::DEFAULT_UTM;
        if (str_contains($draft->link, 'utm_source=')) {
            return $draft->link;
        }

        $fragment = '';
        $link = $draft->link;
        if (($pos = strpos($link, '#')) !== false) {
            $fragment = substr($link, $pos);
            $link = substr($link, 0, $pos);
        }

        return $link.(str_contains($link, '?') ? '&' : '?').$utm.$fragment;
    }

    private function status(?string $operation): ?string
    {
        return match ($operation) {
            'ENABLE' => 'ACTIVE',
            'DISABLE' => 'PAUSED',
            default => $operation,
        };
    }

    /** Ids go into request bodies and filters, so only digits are accepted. */
    private function numericId(string $id): string
    {
        if ($id === '' || ! ctype_digit($id)) {
            throw new AdsApiException('Invalid TikTok id.');
        }

        return $id;
    }

    private function advertiserId(AdAccount $a): string
    {
        return $this->numericId((string) $a->external_id);
    }

    private function token(AdAccount $a): string
    {
        $token = $a->connection->credentials['access_token'] ?? null;
        if (! $token) {
            throw new AdsApiException('TikTok access token is missing.');
        }

        return (string) $token;
    }
}
