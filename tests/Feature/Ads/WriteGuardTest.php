<?php

use App\Ads\Control\AdWriteService;
use App\Ads\Control\Jobs\PublishAd;
use App\Ads\Control\PublishService;
use App\Ads\Control\WritableAccounts;
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
use App\Models\AdMaterial;
use App\Models\AdMaterialFile;
use App\Models\AdPlatformConnection;
use App\Models\AdPublication;
use App\Models\AdsAuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    WriteGuard::check($acc, app(FakeAdsDriver::class));

    expect(true)->toBeTrue();
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

    expect(WritableAccounts::list())->toBeNull()->and($can)->toBe([$a->id => true, $b->id => true, $off->id => false]);
});

it('narrows the writable accounts with ads:writable and audits each change', function () {
    $a = AdAccount::factory()->meta()->create(['external_id' => 'act_A']);
    $b = AdAccount::factory()->meta()->create(['external_id' => 'act_B']);
    $adA = Ad::factory()->for($a, 'account')->create();
    $adB = Ad::factory()->for($b, 'account')->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    expect(Artisan::call('ads:writable', ['--set' => 'act_A']))->toBe(0);
    expect(WritableAccounts::list())->toBe(['act_A']);

    wgStop($this, $admin, $b, $adB)->assertStatus(422);
    expect($adB->refresh()->status)->toBe('ACTIVE')->and(Cache::get('ads-fake-writer'))->toBeNull();
    wgStop($this, $admin, $a, $adA)->assertOk();
    expect($adA->refresh()->status)->toBe('PAUSED');

    Artisan::call('ads:writable', ['--list' => true]);
    expect(Artisan::output())->toContain('only act_A');

    expect(Artisan::call('ads:writable', ['--all' => true]))->toBe(0);
    expect(WritableAccounts::list())->toBeNull();
    wgStop($this, $admin, $b, $adB)->assertOk();

    expect(AdsAuditLog::where('action', 'settings.writable_accounts_changed')->count())->toBe(2);
    $first = AdsAuditLog::where('action', 'settings.writable_accounts_changed')->orderBy('id')->first();
    expect($first->before)->toBe(['writable' => 'all_active'])->and($first->after)->toBe(['writable' => ['act_A']]);
});

it('rejects an unknown account in --set and changes nothing', function () {
    AdAccount::factory()->meta()->create(['external_id' => 'act_A']);

    expect(Artisan::call('ads:writable', ['--set' => 'act_A,act_nope']))->toBe(1)
        ->and(WritableAccounts::list())->toBeNull()->and(AdsAuditLog::count())->toBe(0);
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

    WritableAccounts::set(['act_other']);
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
