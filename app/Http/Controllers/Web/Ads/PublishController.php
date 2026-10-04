<?php

namespace App\Http\Controllers\Web\Ads;

use App\Ads\Access\AdsScope;
use App\Ads\AdsSettings;
use App\Ads\Control\AdWriteService;
use App\Ads\Control\PublishService;
use App\Ads\Materials\MaterialService;
use App\Ads\Naming;
use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\SecretScrubber;
use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\AdMaterial;
use App\Models\AdPublication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Publish a material as paused ads: the dialog's live options, the publish itself, and a material's publication list. */
class PublishController extends Controller
{
    public const CTAS = ['SHOP_NOW', 'LEARN_MORE', 'ORDER_NOW', 'SEND_MESSAGE'];

    public const MAX_CAPTIONS = 5;

    /**
     * Without `account`: the accounts the user may publish into. With it: that account's live campaigns (with ad sets)
     * and page/Instagram identities, plus the identity used last time. With `material`: the link the ads will carry.
     */
    public function options(Request $request, AdWriteService $writes, DriverFactory $drivers, AdsSettings $settings, PublishService $publish): JsonResponse
    {
        $user = $request->user();
        abort_unless(MaterialService::canOperate($user), 403);

        $material = $request->filled('material') ? AdMaterial::query()->with('product')->findOrFail((int) $request->query('material')) : null;
        $link = $material === null ? null : $publish->link($material);

        if (! $request->filled('account')) {
            $accounts = AdAccount::query()->where('is_active', true)->whereIn('platform', [AdPlatform::Meta->value, AdPlatform::Tiktok->value])->orderBy('name')->get();
            $can = $writes->canWriteMany($user, $accounts);

            return response()->json([
                'accounts' => $accounts->filter(fn (AdAccount $a) => $can[$a->id] ?? false)
                    ->map(fn (AdAccount $a) => ['id' => $a->id, 'name' => $a->name, 'platform' => $a->platform])->values()->all(),
                'link' => $link,
            ]);
        }

        $account = AdAccount::query()->findOrFail((int) $request->query('account'));
        abort_unless($writes->canWrite($user, $account) && $account->platform !== AdPlatform::Google->value, 403);

        try {
            $writer = $drivers->writer(AdPlatform::from($account->platform));
            $campaigns = $writer->liveCampaigns($account);
            $identities = $writer->identities($account);
        } catch (AdsApiException $e) {
            $message = $e instanceof RateLimited ? __('ads.errors.rate_limited') : (trim(SecretScrubber::scrub($e->getMessage())) ?: __('ads.errors.failed'));

            return response()->json(['message' => $message], 422);
        }

        return response()->json([
            'campaigns' => array_map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'status' => $c->status, 'objective' => $c->objective,
                'naming_ok' => Naming::checkCampaign($c->name),
                'adsets' => array_map(fn (array $s) => $s + ['naming_ok' => Naming::checkAdSet((string) ($s['name'] ?? ''))], $c->adSets),
            ], $campaigns),
            'identities' => array_map(fn ($i) => ['page_id' => $i->pageId, 'page_name' => $i->pageName, 'instagram_id' => $i->instagramId], $identities),
            'last_identity' => $settings->get(PublishService::identityKey($account)),
            'link' => $link,
        ]);
    }

    public function publish(Request $request, AdMaterial $material, AdWriteService $writes, PublishService $publish): JsonResponse
    {
        $user = $request->user();
        abort_unless(MaterialService::canOperate($user), 403);

        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('ad_accounts', 'id')],
            'campaign_id' => ['required', 'string', 'max:255'],
            'campaign_name' => ['nullable', 'string', 'max:500'],
            'adset_id' => ['required', 'string', 'max:255'],
            'adset_name' => ['nullable', 'string', 'max:500'],
            'identity' => ['required', 'array'],
            'identity.page_id' => ['required', 'string', 'max:255'],
            'identity.page_name' => ['nullable', 'string', 'max:255'],
            'identity.instagram_id' => ['nullable', 'string', 'max:255'],
            'file_ids' => ['required', 'array', 'min:1', 'max:20'],
            'file_ids.*' => ['integer', 'distinct'],
            'captions' => ['required', 'array', 'min:1', 'max:'.self::MAX_CAPTIONS],
            'captions.*.headline' => ['required', 'string', 'max:255'],
            'captions.*.primary_text' => ['required', 'string', 'max:2000'],
            'captions.*.cta' => ['required', Rule::in(self::CTAS)],
        ]);

        $account = AdAccount::query()->findOrFail($data['account_id']);
        abort_unless($writes->canWrite($user, $account) && $account->platform !== AdPlatform::Google->value, 403);

        $rows = $publish->publish($user, $material->load('product'), $account, $data);

        return response()->json([
            'ok' => true,
            'message' => __('ads.publish.queued'),
            'publications' => $rows->map(fn (AdPublication $p) => self::row($p->setRelation('account', $account)))->values()->all(),
        ]);
    }

    /** The material's publications, newest first; buyers see their own accounts' only, content nothing. */
    public function index(Request $request, AdMaterial $material, AdsScope $scope): JsonResponse
    {
        $user = $request->user();
        abort_unless(MaterialService::canOperate($user), 403);

        $allowed = $scope->accountIds($user);
        $rows = AdPublication::query()->with('account:id,external_id,name,platform')->where('ad_material_id', $material->id)
            ->when($allowed !== null, fn ($q) => $q->whereIn('ad_account_id', $allowed))
            ->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (AdPublication $p) => self::row($p))->values()->all()]);
    }

    /** @return array<string, mixed> */
    private static function row(AdPublication $p): array
    {
        $managerUrl = null;
        if ($p->platform === AdPlatform::Meta->value && $p->external_ad_id !== null && $p->account !== null) {
            $managerUrl = 'https://adsmanager.facebook.com/adsmanager/manage/ads?act='.preg_replace('/^act_/', '', $p->account->external_id).'&selected_ad_ids='.$p->external_ad_id;
        }

        return [
            'id' => $p->id, 'ad_name' => $p->ad_name, 'status' => $p->status, 'error' => $p->error, 'platform' => $p->platform,
            'account' => $p->account?->name, 'campaign' => $p->campaign_name, 'adset' => $p->adset_name, 'headline' => $p->headline,
            'external_ad_id' => $p->external_ad_id, 'manager_url' => $managerUrl, 'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
