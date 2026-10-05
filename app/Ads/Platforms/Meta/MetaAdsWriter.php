<?php

namespace App\Ads\Platforms\Meta;

use App\Ads\Platforms\AdPlatformWriter;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\CreativeRejected;
use App\Ads\Platforms\Data\AdDraft;
use App\Ads\Platforms\Data\CampaignNode;
use App\Ads\Platforms\Data\Identity;
use App\Ads\Platforms\Data\MediaRef;
use App\Ads\Platforms\Data\ObjectState;
use App\Ads\Platforms\RateLimited;
use App\Models\AdAccount;
use App\Models\AdMaterialFile;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MetaAdsWriter implements AdPlatformWriter
{
    public function __construct(private readonly MetaAdsApi $api) {}

    public function liveCampaigns(AdAccount $a): array
    {
        $rows = $this->api->paginate($this->token($a), $this->actId($a).'/campaigns', [
            'fields' => 'id,name,status,objective,adsets.limit(100){id,name,status}',
            'effective_status' => json_encode(['ACTIVE', 'PAUSED']),
            'limit' => 50,
        ]);

        return array_values(array_map(fn (array $r) => new CampaignNode(
            id: (string) $r['id'],
            name: (string) ($r['name'] ?? $r['id']),
            status: $r['status'] ?? null,
            objective: $r['objective'] ?? null,
            adSets: array_values(array_map(fn (array $s) => [
                'id' => (string) $s['id'],
                'name' => (string) ($s['name'] ?? $s['id']),
                'status' => $s['status'] ?? null,
            ], $r['adsets']['data'] ?? [])),
        ), $rows));
    }

    public function identities(AdAccount $a): array
    {
        $rows = $this->api->paginate($this->token($a), $this->actId($a).'/promote_pages', [
            'fields' => 'id,name,instagram_business_account{id}',
            'limit' => 50,
        ]);

        return array_values(array_map(fn (array $r) => new Identity(
            pageId: (string) $r['id'],
            pageName: (string) ($r['name'] ?? $r['id']),
            instagramId: isset($r['instagram_business_account']['id']) ? (string) $r['instagram_business_account']['id'] : null,
        ), $rows));
    }

    public function uploadMedia(AdAccount $a, AdMaterialFile $file): MediaRef
    {
        $this->api->backOffIfBusy($this->token($a));

        return str_starts_with((string) $file->mime, 'video/')
            ? $this->uploadVideo($a, $file)
            : $this->uploadImage($a, $file);
    }

    /** @throws AdsApiException when Meta reports the video as failed (readable; the caller re-uploads next time) */
    public function mediaReady(AdAccount $a, MediaRef $ref): bool
    {
        if ($ref->kind !== 'video') {
            return true;
        }
        $res = $this->api->get($this->token($a), $this->numericId($ref->id), ['fields' => 'status']);
        $status = $res['status']['video_status'] ?? null;
        if ($status === 'error') {
            $why = '';
            foreach (['processing_phase', 'uploading_phase', 'publishing_phase'] as $phase) {
                $why = trim((string) ($res['status'][$phase]['errors'][0]['message'] ?? ''));
                if ($why !== '') {
                    break;
                }
            }

            throw new AdsApiException('Meta could not process the video'.($why !== '' ? ': '.$why : '.').' Upload it again or use another file.');
        }

        return $status === 'ready';
    }

    public function createPausedAd(AdAccount $a, AdDraft $draft): string
    {
        // Everything up to the creative happens BEFORE the ad request: a failure here cannot leave an ad behind.
        try {
            $token = $this->token($a);
            $act = $this->actId($a);
            $this->api->backOffIfBusy($token);
            $creativeId = $this->createCreative($a, $token, $act, $draft);
        } catch (AdsApiException $e) {
            throw CreativeRejected::from($e);
        }

        $draft->adRequestSending();
        $ad = $this->api->post($token, $act.'/ads', [
            'name' => $draft->name,
            'adset_id' => $draft->adSetId,
            'creative' => json_encode(['creative_id' => $creativeId]),
            'status' => 'PAUSED',
        ]);
        if (empty($ad['id'])) {
            throw new AdsApiException('Meta did not return an ad id.');
        }

        return (string) $ad['id'];
    }

    public function setStatus(AdAccount $a, string $level, string $externalId, string $status): void
    {
        if (! in_array($level, ['campaign', 'adset', 'ad'], true)) {
            throw new AdsApiException('Unknown ad level: '.$level);
        }
        $externalId = $this->numericId($externalId);
        $token = $this->token($a);
        $activate = strtolower($status) === 'active';
        // A pause stops spend, so it never waits on our own usage back-off: if Meta really is
        // throttling, it answers with a rate code and the action is logged as an error.
        if ($activate) {
            $this->api->backOffIfBusy($token);
        }
        $this->api->post($token, $externalId, ['status' => $activate ? 'ACTIVE' : 'PAUSED'], $this->writeTimeout());
    }

    /** Fields of one live read per level: the object's own status and budgets plus its parents'. */
    private const READ_FIELDS = [
        'ad' => 'status,effective_status,adset{status,daily_budget,lifetime_budget,end_time},campaign{status,daily_budget,lifetime_budget,stop_time}',
        'adset' => 'status,effective_status,daily_budget,lifetime_budget,end_time,campaign{status,daily_budget,lifetime_budget,stop_time}',
        'campaign' => 'status,effective_status,daily_budget,lifetime_budget,stop_time',
    ];

    /**
     * One GET with the write timeout. ASSUMPTION: Meta returns budgets in the account currency's minor unit (EGP offset
     * 100); verify with one GET on a real ad set before trusting the budget cap (runbook step 3).
     */
    public function readObject(AdAccount $a, string $level, string $externalId): ObjectState
    {
        if (! isset(self::READ_FIELDS[$level])) {
            throw new AdsApiException('Unknown ad level: '.$level);
        }
        $res = $this->api->get($this->token($a), $this->numericId($externalId), ['fields' => self::READ_FIELDS[$level]], $this->writeTimeout());

        $parents = [];
        foreach (['adset' => 'end_time', 'campaign' => 'stop_time'] as $parent => $endField) {
            if ($parent === $level || ! isset($res[$parent]) || ! is_array($res[$parent])) {
                continue;
            }
            $p = $res[$parent];
            $parents[] = [
                'level' => $parent,
                'status' => isset($p['status']) ? (string) $p['status'] : null,
                'dailyBudgetMinor' => self::minor($p['daily_budget'] ?? null),
                'lifetimeBudgetMinor' => self::minor($p['lifetime_budget'] ?? null),
                'endsAt' => self::time($p[$endField] ?? null),
            ];
        }

        return new ObjectState(
            status: isset($res['status']) ? (string) $res['status'] : null,
            effectiveStatus: isset($res['effective_status']) ? (string) $res['effective_status'] : null,
            dailyBudgetMinor: self::minor($res['daily_budget'] ?? null),
            lifetimeBudgetMinor: self::minor($res['lifetime_budget'] ?? null),
            endsAt: self::time($res[$level === 'campaign' ? 'stop_time' : 'end_time'] ?? null),
            currency: (string) ($a->currency ?: 'EGP'),
            parents: $parents,
        );
    }

    /** Meta sends budgets as digit strings; "0" or absent means no such budget. */
    private static function minor(mixed $v): ?int
    {
        if (! is_numeric($v)) {
            return null;
        }
        $n = (int) $v;

        return $n > 0 ? $n : null;
    }

    private static function time(mixed $v): ?CarbonImmutable
    {
        if (! is_string($v) || trim($v) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($v)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function writeTimeout(): int
    {
        return max(1, (int) config('crm.ads.write.timeout_seconds', 20));
    }

    /** @return string the creative id */
    private function createCreative(AdAccount $a, string $token, string $act, AdDraft $draft): string
    {
        $cta = ['type' => $draft->cta, 'value' => ['link' => $draft->link]];
        $spec = ['page_id' => $draft->identity->pageId];
        if ($draft->identity->instagramId) {
            $spec['instagram_user_id'] = $draft->identity->instagramId;
        }

        if ($draft->media->kind === 'video') {
            // Meta refuses video_data without a thumbnail (image_url or image_hash).
            $spec['video_data'] = [
                'video_id' => $draft->media->id,
                'message' => $draft->primaryText,
                'title' => $draft->headline,
                'call_to_action' => $cta,
            ] + $this->videoThumbnail($token, $act, $draft);
        } else {
            $spec['link_data'] = [
                'image_hash' => $draft->media->id,
                'link' => $draft->link,
                'message' => $draft->primaryText,
                'name' => $draft->headline,
                'call_to_action' => $cta,
            ];
        }

        $creative = $this->api->post($token, $act.'/adcreatives', [
            'name' => $draft->name,
            'object_story_spec' => json_encode($spec),
            'url_tags' => $draft->urlTags,
        ]);
        if (empty($creative['id'])) {
            throw new AdsApiException('Meta did not return a creative id.');
        }

        return (string) $creative['id'];
    }

    /**
     * The draft's thumbnail URL, else the picture Meta made for the (ready) video, else the local poster uploaded to
     * /adimages and passed as image_hash.
     *
     * @return array{image_url: string}|array{image_hash: string}
     */
    private function videoThumbnail(string $token, string $act, AdDraft $draft): array
    {
        if ($draft->thumbnailUrl) {
            return ['image_url' => $draft->thumbnailUrl];
        }

        try {
            $res = $this->api->get($token, $this->numericId($draft->media->id), ['fields' => 'picture,thumbnails{uri,is_preferred}']);
            $thumbs = array_values(array_filter((array) ($res['thumbnails']['data'] ?? []), fn ($t) => is_array($t) && ! empty($t['uri'])));
            usort($thumbs, fn ($x, $y) => (int) ! empty($y['is_preferred']) <=> (int) ! empty($x['is_preferred']));
            if ($thumbs !== []) {
                return ['image_url' => (string) $thumbs[0]['uri']];
            }
            if (! empty($res['picture'])) {
                return ['image_url' => (string) $res['picture']];
            }
        } catch (RateLimited $e) {
            throw $e;
        } catch (AdsApiException) {
            // fall back to the local poster
        }

        if ($draft->posterDisk && $draft->posterPath) {
            try {
                $contents = Storage::disk($draft->posterDisk)->get($draft->posterPath);
            } catch (Throwable) {
                $contents = null;
            }
            if (is_string($contents) && $contents !== '') {
                return ['image_hash' => $this->uploadImageBytes($token, $act, $contents, 'poster.jpg')];
            }
        }

        throw new AdsApiException('Meta has no thumbnail for this video yet and there is no poster image; try again in a few minutes.');
    }

    private function uploadVideo(AdAccount $a, AdMaterialFile $file): MediaRef
    {
        $token = $this->token($a);
        $path = $this->actId($a).'/advideos';
        $disk = Storage::disk($file->disk);
        $size = (int) ($file->size ?: $disk->size($file->path));

        $start = $this->api->post($token, $path, ['upload_phase' => 'start', 'file_size' => $size]);
        $session = (string) ($start['upload_session_id'] ?? '');
        $videoId = (string) ($start['video_id'] ?? '');
        if ($session === '' || $videoId === '') {
            throw new AdsApiException('Meta did not start the video upload.');
        }

        $stream = $disk->readStream($file->path);
        if (! is_resource($stream)) {
            throw new AdsApiException('The video file cannot be read.');
        }

        try {
            $from = (int) ($start['start_offset'] ?? 0);
            $to = (int) ($start['end_offset'] ?? 0);
            while ($from < $to) {
                // Only the requested byte range is ever in memory.
                if (fseek($stream, $from) !== 0) {
                    throw new AdsApiException('The video file cannot be read at the requested offset.');
                }
                $want = $to - $from;
                $chunk = '';
                while (strlen($chunk) < $want && ! feof($stream)) {
                    $part = fread($stream, $want - strlen($chunk));
                    if ($part === false || $part === '') {
                        break;
                    }
                    $chunk .= $part;
                }
                if ($chunk === '') {
                    throw new AdsApiException('The video file ended before Meta finished receiving it.');
                }
                $res = $this->api->postMultipart($token, $path, [
                    'upload_phase' => 'transfer',
                    'upload_session_id' => $session,
                    'start_offset' => $from,
                ], 'video_file_chunk', $chunk, $file->original_name ?: 'video.mp4');

                $next = (int) ($res['start_offset'] ?? $to);
                if ($next <= $from) {
                    throw new AdsApiException('Meta did not advance the video upload.');
                }
                $from = $next;
                $to = (int) ($res['end_offset'] ?? $to);
            }
        } finally {
            fclose($stream);
        }

        $done = $this->api->post($token, $path, ['upload_phase' => 'finish', 'upload_session_id' => $session]);
        if (empty($done['success'])) {
            throw new AdsApiException('Meta did not confirm the video upload.');
        }

        return new MediaRef('video', $videoId, false);
    }

    private function uploadImage(AdAccount $a, AdMaterialFile $file): MediaRef
    {
        $contents = Storage::disk($file->disk)->get($file->path);
        if ($contents === null) {
            throw new AdsApiException('The image file cannot be read.');
        }

        return new MediaRef('image', $this->uploadImageBytes($this->token($a), $this->actId($a), $contents, $file->original_name ?: 'image.jpg'), true);
    }

    /** @return string the image hash */
    private function uploadImageBytes(string $token, string $act, string $contents, string $name): string
    {
        $res = $this->api->postMultipart($token, $act.'/adimages', [], 'filename', $contents, $name);
        $hash = null;
        foreach ((array) ($res['images'] ?? []) as $img) {
            $hash = $img['hash'] ?? null;
            break;
        }
        if (! $hash) {
            throw new AdsApiException('Meta did not return an image hash.');
        }

        return (string) $hash;
    }

    /** Ids go into URL paths, so only digits are accepted. */
    private function numericId(string $id): string
    {
        if ($id === '' || ! ctype_digit($id)) {
            throw new AdsApiException('Invalid Meta id.');
        }

        return $id;
    }

    private function token(AdAccount $a): string
    {
        try {
            $token = $a->connection->credentials['access_token'] ?? null;
        } catch (DecryptException) {
            throw new AdsApiException('credentials unreadable, re-enter the token');
        }
        if (! $token) {
            throw new AdsApiException('Meta access token is missing.');
        }

        return (string) $token;
    }

    private function actId(AdAccount $a): string
    {
        return str_starts_with($a->external_id, 'act_') ? $a->external_id : 'act_'.$a->external_id;
    }
}
