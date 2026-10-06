<?php

namespace Tests\Support;

use App\Ads\Launch\LandingProbe;
use App\Ads\Launch\LaunchState;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdCampaign;
use App\Models\AdLaunch;
use App\Models\AdMaterial;
use App\Models\AdMaterialFile;
use App\Models\AdPublication;
use App\Models\AdSet;
use App\Models\BotSetting;
use App\Models\MediaBuyer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** One Le Voile launch world for the S1 tests: fake writer, fake landing probe, every role. */
final class LaunchWorld
{
    private static int $seq = 0;

    public static function boot(): void
    {
        Http::preventStrayRequests();
        Cache::forget('ads-fake-writer');
        FakeAdsDriver::reset();
        FakeLandingProbe::$status = 200;
        FakeLandingProbe::$urls = [];
        app()->instance(LandingProbe::class, new FakeLandingProbe);
        config([
            'crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake',
            'crm.ads.write_sandbox_accounts' => [], 'crm.ads.write.enabled' => true,
        ]);
    }

    /** @return array{account: AdAccount, campaign: AdCampaign, adset: AdSet, buyerUser: User, buyer: MediaBuyer, content: User, manager: User, admin: User, supervisor: User, agent: User, product: Product, material: AdMaterial, files: list<AdMaterialFile>} */
    public static function make(): array
    {
        $account = AdAccount::factory()->meta()->create(['name' => 'LV Main', 'currency' => 'EGP']);
        $campaign = AdCampaign::factory()->create(['ad_account_id' => $account->id, 'name' => 'LV | Abaya | Sales | Ali | 261004', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES']);
        $adset = AdSet::factory()->create(['ad_campaign_id' => $campaign->id, 'name' => 'Broad | EG | Advantage+', 'status' => 'ACTIVE']);
        $buyerUser = User::factory()->create(['role' => UserRole::MediaBuyer, 'name' => 'Ali']);
        $buyer = MediaBuyer::factory()->create(['user_id' => $buyerUser->id, 'name' => 'Ali', 'is_active' => true]);
        AdAccountAssignment::factory()->create(['ad_account_id' => $account->id, 'media_buyer_id' => $buyer->id, 'starts_on' => '2026-01-01', 'ends_on' => null]);
        $adset->forceFill(['open_for_drafts_at' => now(), 'open_for_drafts_by_id' => $buyerUser->id])->save();

        $product = Product::factory()->create(['handle' => 'silk-abaya', 'title' => 'Silk abaya', 'status' => 'active']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 450, 'inventory_quantity' => 8]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 450, 'inventory_quantity' => 6]);

        $content = User::factory()->create(['role' => UserRole::Content, 'name' => 'Sara']);
        $material = AdMaterial::factory()->create(['product_id' => $product->id, 'title' => 'Silk reel', 'types' => ['reel'], 'status' => 'new', 'created_by_id' => $content->id]);
        $files = [
            AdMaterialFile::factory()->create(['ad_material_id' => $material->id, 'mime' => 'video/mp4', 'width' => 1080, 'height' => 1920, 'duration' => 20, 'thumb_path' => 'ads/materials/thumb.jpg', 'size' => 5_000_000, 'sort' => 0]),
            AdMaterialFile::factory()->create(['ad_material_id' => $material->id, 'mime' => 'image/jpeg', 'width' => 1080, 'height' => 1350, 'size' => 400_000, 'sort' => 1]),
        ];

        return [
            'account' => $account, 'campaign' => $campaign, 'adset' => $adset, 'buyerUser' => $buyerUser, 'buyer' => $buyer,
            'content' => $content, 'product' => $product, 'material' => $material, 'files' => $files,
            'manager' => User::factory()->adsAuthority()->create(['role' => UserRole::Supervisor, 'name' => 'Mona']),
            'admin' => User::factory()->adsAuthority()->create(['role' => UserRole::Admin, 'name' => 'Owner']),
            'supervisor' => User::factory()->create(['role' => UserRole::Supervisor, 'name' => 'Hany']),
            'agent' => User::factory()->create(['role' => UserRole::Moderator]),
        ];
    }

    /** @return array{headline: string, primary_text: string, cta: string} */
    public static function caption(int $n = 1): array
    {
        return ['headline' => 'عباية حرير '.$n, 'primary_text' => 'عباية حرير ناعمة بـ 450 جنيه بس', 'cta' => 'SHOP_NOW'];
    }

    /**
     * A launch built directly in $state (no transition ran). Past the forward it has done, linked publications and local
     * ads (PAUSED, or ACTIVE when live) so the approve path and G1 have real rows.
     *
     * @param  array<string, mixed>  $attrs
     */
    public static function launch(array $w, LaunchState $state = LaunchState::Draft, array $attrs = []): AdLaunch
    {
        $l = AdLaunch::create([
            'ad_material_id' => $w['material']->id, 'ad_account_id' => $w['account']->id, 'ad_set_id' => $w['adset']->id,
            'campaign_external_id' => $w['campaign']->external_id, 'campaign_name' => $w['campaign']->name,
            'adset_external_id' => $w['adset']->external_id, 'adset_name' => $w['adset']->name,
            'link' => rtrim(BotSetting::current()->storeUrl(), '/').'/products/silk-abaya',
            'file_ids' => [$w['files'][0]->id], 'captions' => [self::caption()],
            'state' => $state, 'prepared_by_id' => $w['content']->id,
        ]);
        $past = ! in_array($state, [LaunchState::Draft, LaunchState::ChangesRequested], true);
        $created = in_array($state, [LaunchState::AwaitingApproval, LaunchState::Launching, LaunchState::Live, LaunchState::Stopped, LaunchState::Retired], true);
        $l->forceFill(array_merge(
            $past ? ['reviewer_buyer_id' => $w['buyer']->id, 'submitted_at' => now()->subHours(2)] : [],
            $created ? ['forwarded_by_id' => $w['buyerUser']->id, 'forwarded_at' => now()->subHour(), 'awaiting_at' => now()->subMinutes(30), 'expires_at' => now()->addDays(7)->subMinutes(30), 'identity' => ['page_id' => 'fake_page_1', 'page_name' => 'Le Voile', 'instagram_id' => null]] : [],
            in_array($state, [LaunchState::Live, LaunchState::Stopped, LaunchState::Retired], true) ? ['approved_at' => now()->subMinutes(10), 'decided_by_id' => $w['manager']->id, 'live_at' => now()->subMinutes(10)] : [],
            $attrs,
        ))->save();
        if ($created) {
            self::pausedAds($w, $l, $state === LaunchState::Live ? 'ACTIVE' : 'PAUSED');
        }

        return $l->fresh();
    }

    /** One done + linked publication and one local ad per file x caption of the launch. */
    public static function pausedAds(array $w, AdLaunch $l, string $status = 'PAUSED'): void
    {
        foreach ((array) $l->file_ids as $f => $fileId) {
            foreach (array_values((array) $l->captions) as $i => $c) {
                $ext = 'lw_ad_'.(++self::$seq);
                $name = 'M'.$w['material']->id.' | Reel | C'.($f * count((array) $l->captions) + $i + 1);
                AdPublication::create([
                    'ad_material_id' => $w['material']->id, 'ad_material_file_id' => $fileId, 'ad_account_id' => $w['account']->id, 'platform' => 'meta',
                    'campaign_external_id' => $l->campaign_external_id, 'campaign_name' => $l->campaign_name,
                    'adset_external_id' => $l->adset_external_id, 'adset_name' => $l->adset_name, 'identity' => $l->identity,
                    'caption_index' => $f * count((array) $l->captions) + $i + 1, 'headline' => $c['headline'], 'primary_text' => $c['primary_text'], 'cta' => $c['cta'],
                    'ad_name' => $name, 'link' => (string) $l->link, 'url_tags' => 'utm_source=meta&utm_medium=paid&utm_campaign={{campaign.name}}&utm_content={{ad.id}}',
                    'status' => AdPublication::DONE, 'external_ad_id' => $ext, 'linked_at' => now(), 'ad_launch_id' => $l->id, 'created_by_id' => $w['buyerUser']->id,
                ]);
                $ad = Ad::factory()->for($w['account'], 'account')->create([
                    'external_id' => $ext, 'name' => $name, 'ad_campaign_id' => $w['campaign']->id, 'ad_set_id' => $w['adset']->id,
                    'status' => $status, 'effective_status' => $status,
                ]);
                $w['material']->ads()->syncWithoutDetaching([$ad->id]);
            }
        }
    }

    /** Session stamp for routes behind RequirePassword (G3). */
    public static function confirmed(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }
}
