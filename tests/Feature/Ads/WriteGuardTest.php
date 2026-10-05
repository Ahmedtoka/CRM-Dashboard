<?php

use App\Ads\Control\AdWriteService;
use App\Ads\Control\Jobs\PublishAd;
use App\Ads\Control\PublishService;
use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\Data\MediaRef;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Platforms\Meta\MetaAdsWriter;
use App\Ads\Platforms\WriteGuard;
use App\Ads\Platforms\WriteRefused;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAction;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\AdMaterial;
use App\Models\AdMaterialFile;
use App\Models\AdPlatformConnection;
use App\Models\AdPublication;
use App\Models\AdsAuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake', 'crm.ads.write_sandbox_accounts' => []]);
    $this->withoutVite();
});

function wgStop($test, User $u, AdAccount $acc, Ad $ad)
{
    return $test->actingAs($u)->postJson('/ads/actions/status', ['account_id' => $acc->id, 'level' => 'ad', 'external_id' => $ad->external_id, 'status' => 'paused', 'reason' => 'x']);
}

function wgLiveAccount(string $externalId = 'act_9'): AdAccount
{
    $c = AdPlatformConnection::factory()->meta()->create(['credentials' => ['access_token' => 'EAATESTTOKEN123']]);

    return AdAccount::factory()->meta()->create(['external_id' => $externalId, 'connection_id' => $c->id]);
}

it('lets the fake writer through outside production', function () {
    $acc = AdAccount::factory()->meta()->create();

    expect(fn () => WriteGuard::check($acc, app(FakeAdsDriver::class)))->not->toThrow(WriteRefused::class);

});

it('refuses the fake writer in production with no platform call', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    app()->detectEnvironment(fn () => 'production');

    try {
        WriteGuard::check($acc, app(FakeAdsDriver::class));
        $this->fail('expected a refusal');
    } catch (WriteRefused $e) {
        expect($e->reason)->toBe('fake_writer_in_production');
    }

    expect(fn () => app(AdWriteService::class)->setStatus($admin, $acc, 'ad', $ad->external_id, 'paused', null))
        ->toThrow(ValidationException::class, __('ads.errors.fake_writer_in_production'));
    expect(Cache::get('ads-fake-writer'))->toBeNull()->and($ad->refresh()->status)->toBe('ACTIVE')
        ->and(AdAction::first()->error)->toBe('fake_writer_in_production');
    Http::assertNothingSent();
});

it('keeps a live writer to sandbox accounts outside production', function () {
    config(['crm.ads.drivers.meta' => 'live']);
    $acc = wgLiveAccount();
    $writer = app(DriverFactory::class)->writer(AdPlatform::Meta);
    expect($writer)->toBeInstanceOf(MetaAdsWriter::class);

    try {
        WriteGuard::check($acc, $writer);
        $this->fail('expected a refusal');
    } catch (WriteRefused $e) {
        expect($e->reason)->toBe('sandbox_only');
    }

    $ad = Ad::factory()->for($acc, 'account')->create(['external_id' => '1234']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    wgStop($this, $admin, $acc, $ad)->assertStatus(422);
    Http::assertNothingSent();

    config(['crm.ads.write_sandbox_accounts' => ['act_9']]);
    Http::fake(['*' => Http::response(['success' => true])]);
    WriteGuard::check($acc, $writer);
    wgStop($this, $admin, $acc, $ad)->assertOk();
    Http::assertSentCount(1);
});

it('allows every active account by default and none when inactive', function () {
    $a = AdAccount::factory()->meta()->create();
    $b = AdAccount::factory()->meta()->create();
    $off = AdAccount::factory()->meta()->create(['is_active' => false]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $can = app(AdWriteService::class)->canWriteMany($admin, [$a, $b, $off]);

    expect($can)->toBe([$a->id => true, $b->id => true, $off->id => false]);
});

it('narrows the writable accounts with ads:writable and audits each change (column-based since B1)', function () {
    $a = AdAccount::factory()->meta()->create(['external_id' => 'act_A']);
    $b = AdAccount::factory()->meta()->create(['external_id' => 'act_B']);
    $adA = Ad::factory()->for($a, 'account')->create();
    $adB = Ad::factory()->for($b, 'account')->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    expect(Artisan::call('ads:writable', ['--set' => 'act_A']))->toBe(0);
    expect($a->fresh()->write_enabled)->toBeTrue()->and($b->fresh()->write_enabled)->toBeFalse();

    wgStop($this, $admin, $b, $adB)->assertStatus(422);
    expect($adB->refresh()->status)->toBe('ACTIVE')->and(Cache::get('ads-fake-writer'))->toBeNull();
    wgStop($this, $admin, $a, $adA)->assertOk();
    expect($adA->refresh()->status)->toBe('PAUSED');

    Artisan::call('ads:writable', ['--list' => true]);
    expect(Artisan::output())->toContain('act_A')->toContain('write_enabled');

    expect(Artisan::call('ads:writable', ['--all' => true]))->toBe(0);
    expect($b->fresh()->write_enabled)->toBeTrue();
    wgStop($this, $admin, $b, $adB)->assertOk();

    expect(AdsAuditLog::where('action', 'account.write_enabled_changed')->count())->toBe(2);
    $first = AdsAuditLog::where('action', 'account.write_enabled_changed')->orderBy('id')->first();
    expect($first->ad_account_id)->toBe($b->id)->and($first->before)->toBe(['write_enabled' => true])->and($first->after)->toBe(['write_enabled' => false]);
});

it('rejects an unknown account in --set and changes nothing', function () {
    $a = AdAccount::factory()->meta()->create(['external_id' => 'act_A']);
    $b = AdAccount::factory()->meta()->create(['external_id' => 'act_B']);

    expect(Artisan::call('ads:writable', ['--set' => 'act_A,act_nope']))->toBe(1)
        ->and($a->fresh()->write_enabled)->toBeTrue()->and($b->fresh()->write_enabled)->toBeTrue()->and(AdsAuditLog::count())->toBe(0);
});

it('fails a queued publish on a non-writable account without a writer call', function () {
    $acc = AdAccount::factory()->meta()->create(['name' => 'LV Main', 'external_id' => 'act_A']);
    $material = AdMaterial::factory()->create(['product_id' => Product::factory()->create(['handle' => 'silk-abaya'])->id, 'types' => ['reel']]);
    $file = AdMaterialFile::factory()->create(['ad_material_id' => $material->id, 'mime' => 'video/mp4']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    Queue::fake();
    $row = app(PublishService::class)->publish($admin, $material, $acc, [
        'campaign_id' => 'c1', 'campaign_name' => 'C', 'adset_id' => 's1', 'adset_name' => 'S',
        'identity' => ['page_id' => 'fake_page_1', 'page_name' => 'Le Voile', 'instagram_id' => null],
        'file_ids' => [$file->id], 'captions' => [['headline' => 'H', 'primary_text' => 'T', 'cta' => 'SHOP_NOW']],
    ])->first();

    $acc->forceFill(['write_enabled' => false])->save();
    $double = new class extends FakeAdsDriver
    {
        public static int $calls = 0;

        public function uploadMedia(AdAccount $a, AdMaterialFile $file): MediaRef
        {
            self::$calls++;

            return parent::uploadMedia($a, $file);
        }
    };
    app()->bind(FakeAdsDriver::class, fn () => $double);

    (new PublishAd($row->id))->handle(app(DriverFactory::class));

    expect($double::$calls)->toBe(0)->and($row->fresh()->status)->toBe(AdPublication::ERROR)
        ->and($row->fresh()->error)->toBe(__('ads.errors.account_not_writable'));
});

function wgPublishBody(AdAccount $acc, AdMaterialFile $file): array
{
    return [
        'account_id' => $acc->id, 'campaign_id' => 'c1', 'campaign_name' => 'C', 'adset_id' => 's1', 'adset_name' => 'S',
        'identity' => ['page_id' => 'p1', 'page_name' => 'LV', 'instagram_id' => null],
        'file_ids' => [$file->id], 'captions' => [['headline' => 'H', 'primary_text' => 'T', 'cta' => 'SHOP_NOW']],
    ];
}

it('refuses publish options in production with the fake writer: 422, no platform call', function () {
    $acc = AdAccount::factory()->meta()->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    app()->detectEnvironment(fn () => 'production');

    $this->actingAs($admin)->getJson("/ads/publish/options?account={$acc->id}")->assertStatus(422)
        ->assertJsonPath('message', __('ads.errors.fake_writer_in_production'));

    expect(Cache::get('ads-fake-writer'))->toBeNull();
    Http::assertNothingSent();
});

it('refuses publish options for a live writer on a non-sandbox account, and a non-writable account', function () {
    config(['crm.ads.drivers.meta' => 'live']);
    $acc = wgLiveAccount();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->getJson("/ads/publish/options?account={$acc->id}")->assertStatus(422)
        ->assertJsonPath('message', __('ads.errors.sandbox_only'));

    $acc->forceFill(['write_enabled' => false])->save();
    $this->actingAs($admin)->getJson("/ads/publish/options?account={$acc->id}")->assertStatus(422)->assertJsonValidationErrors('account_id');
    Http::assertNothingSent();
});

it('refuses publish synchronously: non-writable account 422, live writer outside the sandbox 422, nothing queued', function () {
    Queue::fake();
    $acc = AdAccount::factory()->meta()->create(['external_id' => 'act_A']);
    $material = AdMaterial::factory()->create(['product_id' => Product::factory()->create(['handle' => 'silk-abaya'])->id, 'types' => ['reel']]);
    $file = AdMaterialFile::factory()->create(['ad_material_id' => $material->id, 'mime' => 'video/mp4']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $key = ['Idempotency-Key' => (string) Str::uuid()];

    $acc->forceFill(['write_enabled' => false])->save();
    $this->actingAs($admin)->postJson("/ads/materials/{$material->id}/publish", wgPublishBody($acc, $file), $key)->assertStatus(422)->assertJsonValidationErrors('account_id');

    $acc->forceFill(['write_enabled' => true])->save();
    config(['crm.ads.drivers.meta' => 'live']);
    $this->actingAs($admin)->postJson("/ads/materials/{$material->id}/publish", wgPublishBody($acc, $file), $key)->assertStatus(422)
        ->assertJsonPath('errors.account_id.0', __('ads.errors.sandbox_only'));

    expect(AdPublication::count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('ends a queued publish in error when the guard refuses inside the job, with no upload', function () {
    config(['crm.ads.drivers.meta' => 'live']);
    $acc = wgLiveAccount('act_9');
    $material = AdMaterial::factory()->create(['product_id' => Product::factory()->create(['handle' => 'silk-abaya'])->id, 'types' => ['reel']]);
    $file = AdMaterialFile::factory()->create(['ad_material_id' => $material->id, 'mime' => 'video/mp4']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    Queue::fake();
    $row = app(PublishService::class)->publish($admin, $material, $acc, [
        'campaign_id' => 'c1', 'campaign_name' => 'C', 'adset_id' => 's1', 'adset_name' => 'S',
        'identity' => ['page_id' => 'p1', 'page_name' => 'LV', 'instagram_id' => null],
        'file_ids' => [$file->id], 'captions' => [['headline' => 'H', 'primary_text' => 'T', 'cta' => 'SHOP_NOW']],
    ])->first();
    // act_9 is not in the (empty) sandbox list: the guard refuses at run time.

    (new PublishAd($row->id))->handle(app(DriverFactory::class));

    expect($row->fresh()->status)->toBe(AdPublication::ERROR)->and($row->fresh()->error)->toBe(__('ads.errors.sandbox_only'));
    Http::assertNothingSent();
});

it('shows can_write per account on campaigns, creatives and stop suggestions under an ads:writable override', function () {
    $a = AdAccount::factory()->meta()->create(['name' => 'AccA', 'external_id' => 'act_A']);
    $b = AdAccount::factory()->meta()->create(['name' => 'AccB', 'external_id' => 'act_B']);
    foreach ([$a, $b] as $acc) {
        $camp = AdCampaign::factory()->for($acc, 'account')->create();
        $ad = Ad::factory()->for($acc, 'account')->create(['name' => 'Ad '.$acc->name, 'ad_campaign_id' => $camp->id]);
        foreach (range(1, 6) as $i) {
            AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $acc->id, 'date' => now('Africa/Cairo')->subDays($i)->toDateString(),
                'spend' => 200, 'purchase_value' => 10, 'purchases' => 1, 'impressions' => 1000, 'clicks' => 20, 'reach' => 800]);
        }
    }
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    Artisan::call('ads:writable', ['--set' => 'act_A']);

    $rows = collect($this->actingAs($admin)->get('/ads/creatives?status=all')->assertOk()->viewData('page')['props']['result']['data'])->keyBy('account');
    expect($rows['AccA']['can_write'])->toBeTrue()->and($rows['AccB']['can_write'])->toBeFalse();

    $flat = [];
    $walk = function (array $nodes) use (&$walk, &$flat) {
        foreach ($nodes as $n) {
            $flat[$n['account']][] = $n['can_write'];
            $walk($n['children']);
        }
    };
    $walk($this->actingAs($admin)->get('/ads/campaigns')->assertOk()->viewData('page')['props']['tree']);
    expect(array_unique($flat['AccA']))->toBe([true])->and(array_unique($flat['AccB']))->toBe([false]);

    $sug = collect($this->actingAs($admin)->get('/ads/actions')->assertOk()->viewData('page')['props']['suggestions'])->keyBy('name');
    expect($sug['Ad AccA']['can_write'])->toBeTrue()->and($sug['Ad AccB']['can_write'])->toBeFalse();
});

it('reads no settings at all for a whole page of accounts (the write switch is a column since B1)', function () {
    $accounts = AdAccount::factory()->meta()->count(5)->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $queries = 0;
    DB::listen(function ($q) use (&$queries) {
        if (str_contains($q->sql, 'ads_settings')) {
            $queries++;
        }
    });

    $can = app(AdWriteService::class)->canWriteMany($admin, $accounts);

    expect($queries)->toBe(0)->and(array_unique(array_values($can)))->toBe([true]);
});
