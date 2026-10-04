<?php

namespace App\Ads\Control;

use App\Ads\AdsSettings;
use App\Ads\Control\Jobs\PublishAd;
use App\Ads\Naming;
use App\Ads\Platforms\AdPlatform;
use App\Models\AdAccount;
use App\Models\AdMaterial;
use App\Models\AdMaterialFile;
use App\Models\AdPublication;
use App\Models\BotSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a publish request into one queued ad_publications row per (file x caption) and dispatches PublishAd for each.
 * Scope (who may write into the account) is checked by the caller with AdWriteService::canWrite.
 */
final class PublishService
{
    public const META_URL_TAGS = 'utm_source=meta&utm_medium=paid&utm_campaign={{campaign.name}}&utm_content={{ad.id}}';

    public const TIKTOK_URL_TAGS = 'utm_source=tiktok&utm_medium=paid&utm_campaign=__CAMPAIGN_NAME__&utm_content=__CID__';

    private const TYPE_LABELS = ['reel' => 'Reel', 'carousel' => 'Carousel', 'post' => 'Post', 'story' => 'Story', 'image' => 'Image', 'video' => 'Video'];

    public function __construct(private readonly AdsSettings $settings) {}

    /**
     * @param  array{campaign_id:string, campaign_name?:?string, adset_id:string, adset_name?:?string, identity:array{page_id:string, page_name?:?string, instagram_id?:?string}, file_ids:list<int>, captions:list<array{headline:string, primary_text:string, cta:string}>}  $input
     * @return Collection<int, AdPublication>
     *
     * @throws ValidationException no link to send people to, or a file that is not the material's
     */
    public function publish(User $u, AdMaterial $m, AdAccount $a, array $input): Collection
    {
        $link = $this->link($m);
        if ($link === null) {
            throw ValidationException::withMessages(['link' => __('ads.publish.no_link')]);
        }

        $files = $m->files()->whereIn('id', $input['file_ids'])->get()->keyBy('id');
        $ordered = [];
        foreach ($input['file_ids'] as $id) {
            if (! isset($files[$id])) {
                throw ValidationException::withMessages(['file_ids' => __('ads.publish.bad_file')]);
            }
            $ordered[] = $files[$id];
        }

        $identity = [
            'page_id' => (string) $input['identity']['page_id'],
            'page_name' => (string) ($input['identity']['page_name'] ?? ''),
            'instagram_id' => ($input['identity']['instagram_id'] ?? null) ?: null,
        ];
        $tags = $this->urlTags(AdPlatform::from($a->platform));

        $rows = DB::transaction(function () use ($u, $m, $a, $input, $ordered, $identity, $link, $tags) {
            $made = [];
            foreach ($ordered as $file) {
                foreach (array_values($input['captions']) as $i => $caption) {
                    $made[] = AdPublication::create([
                        'ad_material_id' => $m->id, 'ad_material_file_id' => $file->id, 'ad_account_id' => $a->id, 'platform' => $a->platform,
                        'campaign_external_id' => $input['campaign_id'], 'campaign_name' => $input['campaign_name'] ?? null,
                        'adset_external_id' => $input['adset_id'], 'adset_name' => $input['adset_name'] ?? null,
                        'identity' => $identity, 'caption_index' => $i + 1,
                        'headline' => $caption['headline'], 'primary_text' => $caption['primary_text'], 'cta' => $caption['cta'],
                        'ad_name' => Naming::adName($m->id, self::typeFor($m, $file), $i + 1),
                        'link' => $link, 'url_tags' => $tags, 'status' => AdPublication::QUEUED, 'created_by_id' => $u->id,
                    ]);
                }
            }

            return collect($made);
        });

        $this->settings->set(self::identityKey($a), $identity);
        foreach ($rows as $row) {
            PublishAd::dispatch($row->id);
        }

        return $rows;
    }

    public static function identityKey(AdAccount $a): string
    {
        return 'publish_identity_'.$a->id;
    }

    /** The product page with the store URL, else the material's first website link, else null. */
    public function link(AdMaterial $m): ?string
    {
        $product = $m->product;
        if ($product !== null && trim((string) $product->handle) !== '') {
            return rtrim(BotSetting::current()->storeUrl(), '/').'/products/'.$product->handle;
        }
        foreach ((array) $m->website_links as $url) {
            if (is_string($url) && trim($url) !== '') {
                return trim($url);
            }
        }

        return null;
    }

    public function urlTags(AdPlatform $p): string
    {
        return $p === AdPlatform::Tiktok ? self::TIKTOK_URL_TAGS : self::META_URL_TAGS;
    }

    /** The material's first type; with none, a video file is a Reel and anything else an Image. */
    public static function typeFor(AdMaterial $m, AdMaterialFile $file): string
    {
        $first = strtolower((string) (((array) $m->types)[0] ?? ''));
        if (isset(self::TYPE_LABELS[$first])) {
            return self::TYPE_LABELS[$first];
        }

        return str_starts_with((string) $file->mime, 'video/') ? 'Reel' : 'Image';
    }
}
