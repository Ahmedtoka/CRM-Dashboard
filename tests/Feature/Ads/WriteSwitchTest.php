<?php

use App\Ads\Control\Jobs\PublishAd;
use App\Ads\Control\PublishService;
use App\Ads\Control\Write\WriteSwitch;
use App\Ads\Platforms\Data\MediaRef;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdMaterial;
use App\Models\AdMaterialFile;
use App\Models\AdPublication;
use App\Models\AdsAuditLog;
use App\Models\AdWriteAction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('ads-fake-writer');
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake', 'crm.ads.write_sandbox_accounts' => []]);
    $this->withoutVite();
});

function wsStatus($test, User $u, AdAccount $acc, Ad $ad, string $status)
{
    return $test->actingAs($u)->postJson('/ads/actions/status', ['account_id' => $acc->id, 'level' => 'ad', 'external_id' => $ad->external_id, 'status' => $status, 'reason' => 'x']);
}

function wsMaterial(): array
{
    $material = AdMaterial::factory()->create(['product_id' => Product::factory()->create(['handle' => 'silk-abaya'])->id, 'types' => ['reel']]);
    $file = AdMaterialFile::factory()->create(['ad_material_id' => $material->id, 'mime' => 'video/mp4']);

    return [$material, $file];
}

function wsPublishBody(AdAccount $acc, AdMaterialFile $file): array
{
    return [
        'account_id' => $acc->id, 'campaign_id' => 'c1', 'campaign_name' => 'C', 'adset_id' => 's1', 'adset_name' => 'S',
        'identity' => ['page_id' => 'p1', 'page_name' => 'LV', 'instagram_id' => null],
        'file_ids' => [$file->id], 'captions' => [['headline' => 'H', 'primary_text' => 'T', 'cta' => 'SHOP_NOW']],
    ];
}

it('is on by default and lets Stop through when off', function () {
    expect(WriteSwitch::enabled())->toBeTrue()
        ->and(WriteSwitch::allows('set_status', 'active'))->toBeTrue();

    Artisan::call('ads:writes', ['--off' => true, '--reason' => 'drill']);

    expect(WriteSwitch::enabled())->toBeFalse()
        ->and(WriteSwitch::allows('set_status', 'active'))->toBeFalse()
        ->and(WriteSwitch::allows('publish', null))->toBeFalse()
        ->and(WriteSwitch::allows('set_status', 'paused'))->toBeTrue();
});

it('refuses a Run with 503 writes_disabled and no writer call, while a Stop still works', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $live = Ad::factory()->for($acc, 'account')->create(['status' => 'ACTIVE']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    expect(Artisan::call('ads:writes', ['--off' => true, '--reason' => 'drill']))->toBe(0);
    expect(Artisan::output())->toContain('Stop keeps working');

    wsStatus($this, $admin, $acc, $ad, 'active')->assertStatus(503)
        ->assertJsonPath('code', 'writes_disabled')
        ->assertJsonPath('message', __('ads.errors.writes_disabled'))
        ->assertJsonPath('errors.status.0', __('ads.errors.writes_disabled'));
    expect(Cache::get('ads-fake-writer'))->toBeNull()
        ->and($ad->refresh()->status)->toBe('PAUSED')
        ->and(AdWriteAction::count())->toBe(0)
        ->and(AdsAuditLog::where('action', 'write.refused')->get()->filter(fn ($r) => $r->meta['code'] === 'writes_disabled')->count())->toBe(1);

    wsStatus($this, $admin, $acc, $live, 'paused')->assertOk();
    expect(Cache::get('ads-fake-writer')['statuses'])->toHaveCount(1)
        ->and($live->refresh()->status)->toBe('PAUSED');
});

it('turns off from the deploy config even when the setting is on', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    config(['crm.ads.write.enabled' => false]);

    expect(WriteSwitch::enabled())->toBeFalse();
    wsStatus($this, $admin, $acc, $ad, 'active')->assertStatus(503);
    expect(Cache::get('ads-fake-writer'))->toBeNull();

    Artisan::call('ads:writes');
    expect(Artisan::output())->toContain('config')->toContain('effective: off');
});

it('refuses publish options and publish with 503 when off, nothing queued', function () {
    Queue::fake();
    $acc = AdAccount::factory()->meta()->create();
    [$material, $file] = wsMaterial();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    Artisan::call('ads:writes', ['--off' => true, '--reason' => 'drill']);

    $this->actingAs($admin)->getJson("/ads/publish/options?account={$acc->id}")->assertStatus(503)->assertJsonPath('code', 'writes_disabled');
    $this->actingAs($admin)->getJson('/ads/publish/options')->assertStatus(503)->assertJsonPath('code', 'writes_disabled');
    $this->actingAs($admin)->postJson("/ads/materials/{$material->id}/publish", wsPublishBody($acc, $file), ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(503)->assertJsonPath('code', 'writes_disabled');

    expect(AdPublication::count())->toBe(0)->and(Cache::get('ads-fake-writer'))->toBeNull();
    Queue::assertNothingPushed();
});

it('ends a queued publish in error when the switch went off after queueing, with no upload', function () {
    $acc = AdAccount::factory()->meta()->create();
    [$material, $file] = wsMaterial();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    Queue::fake();
    $row = app(PublishService::class)->publish($admin, $material, $acc, [
        'campaign_id' => 'c1', 'campaign_name' => 'C', 'adset_id' => 's1', 'adset_name' => 'S',
        'identity' => ['page_id' => 'fake_page_1', 'page_name' => 'Le Voile', 'instagram_id' => null],
        'file_ids' => [$file->id], 'captions' => [['headline' => 'H', 'primary_text' => 'T', 'cta' => 'SHOP_NOW']],
    ])->first();
    Artisan::call('ads:writes', ['--off' => true, '--reason' => 'drill']);

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

    expect($double::$calls)->toBe(0)
        ->and($row->fresh()->status)->toBe(AdPublication::ERROR)
        ->and($row->fresh()->error)->toBe(__('ads.errors.writes_disabled'));
});

it('needs a reason to switch off, and audits every change', function () {
    expect(Artisan::call('ads:writes', ['--off' => true]))->toBe(1)
        ->and(WriteSwitch::enabled())->toBeTrue()
        ->and(AdsAuditLog::count())->toBe(0);

    Artisan::call('ads:writes', ['--off' => true, '--reason' => 'drill']);
    Artisan::call('ads:writes', ['--off' => true, '--reason' => 'again']); // already off: no row
    expect(Artisan::call('ads:writes', ['--on' => true]))->toBe(0);

    $rows = AdsAuditLog::where('action', 'settings.writes_enabled_changed')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->before)->toBe(['writes_enabled' => true])->and($rows[0]->after)->toBe(['writes_enabled' => false])
        ->and($rows[0]->meta['reason'])->toBe('drill')
        ->and($rows[1]->after)->toBe(['writes_enabled' => true]);
});

it('lets a Run through again after --on', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['status' => 'PAUSED']);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    Artisan::call('ads:writes', ['--off' => true, '--reason' => 'drill']);
    Artisan::call('ads:writes', ['--on' => true]);

    wsStatus($this, $admin, $acc, $ad, 'active')->assertOk();
    expect($ad->refresh()->status)->toBe('ACTIVE');
});

it('refuses --on and --off together', function () {
    expect(Artisan::call('ads:writes', ['--on' => true, '--off' => true, '--reason' => 'x']))->toBe(1);
});
