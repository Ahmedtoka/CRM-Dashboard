<?php

use App\Ads\AdsSettings;
use App\Ads\Buyers\AssignmentService;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Sync\SyncAdAccount;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdDailyMetric;
use App\Models\AdPlatformConnection;
use App\Models\BuyerTarget;
use App\Models\MediaBuyer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake']);
    $this->withoutVite();
});

function adsPgUser(UserRole $role): User
{
    return User::factory()->create(['role' => $role]);
}

/** @return array{buyer: MediaBuyer, user: User, account: AdAccount, other: MediaBuyer} */
function adsPgBuyerWorld(): array
{
    $user = adsPgUser(UserRole::MediaBuyer);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id, 'name' => 'Own Buyer']);
    $other = MediaBuyer::factory()->create(['name' => 'Other Buyer']);
    $account = AdAccount::factory()->create();
    app(AssignmentService::class)->assign($account, $buyer, CarbonImmutable::now('Africa/Cairo')->subDays(5));

    return ['buyer' => $buyer, 'user' => $user, 'account' => $account, 'other' => $other];
}

it('renders every report page for an admin with the documented props', function () {
    $admin = adsPgUser(UserRole::Admin);
    $buyer = MediaBuyer::factory()->create();

    $this->actingAs($admin)->get('/ads')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Overview')
        ->has('filters.from')->has('filters.to')->has('filters.platform')->has('filters.buyer')
        ->has('overview.totals.spend')->has('overview.daily')
        ->has('buyers', 1)->has('platforms', 3)
        ->has('sync.last_synced_at')->has('sync.errors'));

    $this->actingAs($admin)->get('/ads/buyers')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Buyers')->has('filters')->has('cards'));

    $this->actingAs($admin)->get('/ads/buyers/'.$buyer->id)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Ads/BuyerShow')->where('buyer.id', $buyer->id)->has('buyer.color')->has('detail'));

    $this->actingAs($admin)->get('/ads/creatives?status=active&sort=roas&per_page=50&q=x')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Creatives')
        ->where('filters.status', 'active')->where('filters.sort', 'roas')->where('filters.per_page', 50)->where('filters.q', 'x')
        ->has('filters.account')->has('filters.page')
        ->has('result.data')->has('result.meta.total')->has('result.counts.all'));

    $this->actingAs($admin)->get('/ads/winners?from=2026-09-20&to=2026-09-22')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Winners')
        ->has('winners')->where('filters.status', 'all')->where('filters.sort', 'score')
        ->where('window.to', '2026-09-22')->where('window.from', '2026-09-16')); // clamped to 7 days
});

it('lets a media buyer see the report pages but not the setup pages', function () {
    $w = adsPgBuyerWorld();
    $this->actingAs($w['user']);

    foreach (['/ads', '/ads/buyers', '/ads/creatives', '/ads/winners'] as $url) {
        $this->get($url)->assertOk();
    }
    $this->get('/ads/buyers/'.$w['buyer']->id)->assertOk();
    $this->get('/ads/accounts')->assertForbidden();
    $this->get('/ads/setup/buyers')->assertForbidden();
    $this->put('/ads/setup/settings', ['tax_rate_percent' => 5])->assertForbidden();
    $this->post('/ads/accounts/'.$w['account']->id.'/assign', ['media_buyer_id' => $w['other']->id, 'starts_on' => '2026-09-30'])->assertForbidden();
});

it('refuses a media buyer another buyers page', function () {
    $w = adsPgBuyerWorld();
    $this->actingAs($w['user'])->get('/ads/buyers/'.$w['other']->id)->assertForbidden();
});

it('limits a buyer to their own buyer option and shares the ads access flags', function () {
    $w = adsPgBuyerWorld();

    $this->actingAs($w['user'])->get('/ads')->assertInertia(fn (Assert $p) => $p
        ->has('buyers', 1)->where('buyers.0.id', $w['buyer']->id)
        ->where('ads.isBuyer', true)->where('ads.canManage', false)->where('ads.canSeeSpend', true)
        ->where('ads.buyerId', $w['buyer']->id));

    $this->actingAs(adsPgUser(UserRole::Admin))->get('/ads')->assertInertia(fn (Assert $p) => $p
        ->where('ads.canManage', true)->where('ads.isBuyer', false)->where('ads.buyerId', null));
});

it('does not compute the ads props outside ads pages', function () {
    $this->actingAs(adsPgUser(UserRole::Admin))->get('/orders')->assertInertia(fn (Assert $p) => $p->where('ads', null));
});

it('hides a buyers out-of-scope creative detail and serves in-scope ones as json', function () {
    $w = adsPgBuyerWorld();
    $mine = Ad::factory()->for($w['account'], 'account')->create(['preview_html' => '<iframe></iframe>']);
    $foreign = Ad::factory()->for(AdAccount::factory()->create(), 'account')->create();

    $this->actingAs($w['user'])->getJson('/ads/creatives/'.$foreign->id)->assertNotFound();
    $this->actingAs($w['user'])->getJson('/ads/creatives/'.$mine->id)->assertOk()
        ->assertJsonPath('id', $mine->id)->assertJsonPath('preview_html', '<iframe></iframe>');
    $this->actingAs(adsPgUser(UserRole::Admin))->getJson('/ads/creatives/'.$foreign->id)->assertOk();
});

it('sends content users to the materials library and refuses moderators', function () {
    $this->actingAs(adsPgUser(UserRole::Content))->get('/ads')->assertRedirect('/ads/materials');
    $this->actingAs(adsPgUser(UserRole::Content))->get('/ads/accounts')->assertRedirect('/ads/materials');
    $this->actingAs(adsPgUser(UserRole::Moderator))->get('/ads')->assertForbidden();
    $this->actingAs(adsPgUser(UserRole::Supervisor))->get('/ads/accounts')->assertOk();
});

it('assigns an account to a buyer and keeps the history', function () {
    $admin = adsPgUser(UserRole::Admin);
    $account = AdAccount::factory()->create();
    $a = MediaBuyer::factory()->create(['name' => 'A']);
    $b = MediaBuyer::factory()->create(['name' => 'B']);

    $this->actingAs($admin)->post("/ads/accounts/{$account->id}/assign", ['media_buyer_id' => $a->id, 'starts_on' => '2026-09-01'])->assertRedirect();
    $this->actingAs($admin)->post("/ads/accounts/{$account->id}/assign", ['media_buyer_id' => $b->id, 'starts_on' => '2026-09-16'])->assertRedirect();

    expect(AdAccountAssignment::where('ad_account_id', $account->id)->count())->toBe(2)
        ->and(AdAccountAssignment::where('media_buyer_id', $a->id)->first()->ends_on->toDateString())->toBe('2026-09-15');

    $this->actingAs($admin)->get('/ads/accounts')->assertInertia(fn (Assert $p) => $p
        ->where('connections.0.accounts.0.buyer.name', 'B')
        ->has('connections.0.accounts.0.history', 2));

    $this->actingAs($admin)->post("/ads/accounts/{$account->id}/assign", ['media_buyer_id' => null, 'starts_on' => '2026-09-20'])->assertRedirect();
    expect(AdAccountAssignment::where('media_buyer_id', $b->id)->first()->ends_on->toDateString())->toBe('2026-09-19');

    $this->actingAs($admin)->post("/ads/accounts/{$account->id}/assign", ['media_buyer_id' => $a->id, 'starts_on' => '2026-09-10'])
        ->assertSessionHasErrors('starts_on');
});

it('stores connection credentials encrypted, syncs the accounts and queues a 90 day backfill', function () {
    Queue::fake();
    $admin = adsPgUser(UserRole::Admin);

    $this->actingAs($admin)->post('/ads/connections', [
        'platform' => 'meta', 'name' => 'Le Voile Meta', 'credentials' => ['access_token' => 'SECRET-TOKEN-123'],
    ])->assertRedirect()->assertSessionHas('status');

    $c = AdPlatformConnection::firstOrFail();
    expect($c->credentials['access_token'])->toBe('SECRET-TOKEN-123')
        ->and($c->status)->toBe('connected')
        ->and(DB::table('ad_platform_connections')->value('credentials'))->not->toContain('SECRET-TOKEN-123')
        ->and($c->accounts()->count())->toBe(3);
    Queue::assertPushed(SyncAdAccount::class, 3);
    Queue::assertPushed(SyncAdAccount::class, fn (SyncAdAccount $j) => $j->days === 90 && $j->kind === 'backfill');

    $page = $this->actingAs($admin)->get('/ads/accounts');
    $page->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Accounts')
        ->where('connections.0.has_token', true)->where('connections.0.driver', 'fake')
        ->where('connections.0.configured.access_token', true)
        ->has('connections.0.accounts', 3)->has('platforms', 3)
        ->where('platforms.0.fields.0.key', 'access_token')->where('platforms.0.fields.0.secret', true));
    expect($page->getContent())->not->toContain('SECRET-TOKEN-123');
    expect($this->actingAs($admin)->post('/ads/connections', ['platform' => 'meta', 'name' => 'Again', 'credentials' => ['access_token' => 'SECRET-TOKEN-456']])->getContent())
        ->not->toContain('SECRET-TOKEN-123');
});

it('validates credentials per platform and stores tiktok advertiser ids as a list', function () {
    Queue::fake();
    $admin = adsPgUser(UserRole::Admin);

    $this->actingAs($admin)->post('/ads/connections', ['platform' => 'tiktok', 'name' => 'TT', 'credentials' => ['access_token' => 't']])
        ->assertSessionHasErrors('credentials.advertiser_ids');
    $this->actingAs($admin)->post('/ads/connections', ['platform' => 'nope', 'name' => 'x', 'credentials' => ['a' => 'b']])
        ->assertSessionHasErrors('platform');
    expect(AdPlatformConnection::count())->toBe(0);

    $this->actingAs($admin)->post('/ads/connections', [
        'platform' => 'tiktok', 'name' => 'TT', 'credentials' => ['access_token' => 't', 'advertiser_ids' => '111, 222 ,,111'],
    ])->assertRedirect();
    expect(AdPlatformConnection::first()->credentials['advertiser_ids'])->toBe(['111', '222']);
});

it('keeps stored secrets when the update leaves a field blank', function () {
    $admin = adsPgUser(UserRole::Admin);
    $c = AdPlatformConnection::factory()->tiktok()->create(['credentials' => ['access_token' => 'OLD', 'advertiser_ids' => ['1']]]);

    $this->actingAs($admin)->put("/ads/connections/{$c->id}", [
        'name' => 'Renamed', 'credentials' => ['access_token' => '', 'advertiser_ids' => '5,6'],
    ])->assertRedirect();
    $c->refresh();
    expect($c->name)->toBe('Renamed')->and($c->credentials)->toBe(['access_token' => 'OLD', 'advertiser_ids' => ['5', '6']]);

    $this->actingAs($admin)->put("/ads/connections/{$c->id}", ['credentials' => ['access_token' => 'NEW']])->assertRedirect();
    expect($c->refresh()->credentials['access_token'])->toBe('NEW');
});

it('tests a connection with the fake driver and reports a failing one', function () {
    $admin = adsPgUser(UserRole::Admin);
    $c = AdPlatformConnection::factory()->create(['status' => 'error', 'last_error' => 'old']);

    $this->actingAs($admin)->postJson("/ads/connections/{$c->id}/test")->assertOk()->assertJson(['ok' => true, 'error' => null]);
    expect($c->refresh()->status)->toBe('connected')->and($c->last_error)->toBeNull();
    $this->actingAs($admin)->post("/ads/connections/{$c->id}/test")->assertRedirect()->assertSessionHas('status');

    app()->bind(FakeAdsDriver::class, fn () => new class extends FakeAdsDriver
    {
        public function test(AdPlatformConnection $c): ?string
        {
            return 'Invalid OAuth access_token=abc123';
        }
    });
    $this->actingAs($admin)->postJson("/ads/connections/{$c->id}/test")->assertOk()->assertJsonPath('ok', false);
    expect($c->refresh()->status)->toBe('error')->and($c->last_error)->not->toContain('abc123');
});

it('queues backfill for new accounts only and a recent sync for known ones', function () {
    Queue::fake();
    $admin = adsPgUser(UserRole::Admin);
    $c = AdPlatformConnection::factory()->create();
    $known = AdAccount::factory()->create(['connection_id' => $c->id, 'external_id' => 'act_1648538895706851']);

    $this->actingAs($admin)->post("/ads/connections/{$c->id}/sync")->assertRedirect()->assertSessionHas('status');

    Queue::assertPushed(SyncAdAccount::class, 3);
    Queue::assertPushed(SyncAdAccount::class, fn (SyncAdAccount $j) => $j->accountId === $known->id && $j->kind === 'recent');
    Queue::assertPushed(SyncAdAccount::class, fn (SyncAdAccount $j) => $j->kind === 'backfill' && $j->days === 90);
});

it('reports a platform error on sync as a validation error', function () {
    Queue::fake();
    app()->bind(FakeAdsDriver::class, fn () => new class extends FakeAdsDriver
    {
        public function accounts(AdPlatformConnection $c): array
        {
            throw new AdsApiException('Token expired');
        }
    });
    $admin = adsPgUser(UserRole::Admin);

    $this->actingAs($admin)->post('/ads/connections', ['platform' => 'meta', 'name' => 'M', 'credentials' => ['access_token' => 'x']])
        ->assertSessionHasErrors('credentials');
    expect(AdPlatformConnection::first()->status)->toBe('error');
    Queue::assertNothingPushed();

    $c = AdPlatformConnection::first();
    $this->actingAs($admin)->post("/ads/connections/{$c->id}/sync")->assertSessionHasErrors('connection');
});

it('syncs one account through the queue and toggles it', function () {
    Queue::fake();
    $admin = adsPgUser(UserRole::Admin);
    $account = AdAccount::factory()->create();

    $this->actingAs($admin)->post("/ads/accounts/{$account->id}/sync")->assertRedirect();
    Queue::assertPushed(SyncAdAccount::class, fn (SyncAdAccount $j) => $j->accountId === $account->id && $j->kind === 'recent');

    $this->actingAs($admin)->patch("/ads/accounts/{$account->id}", ['is_active' => false])->assertRedirect();
    expect($account->refresh()->is_active)->toBeFalse();
});

it('deletes a connection with its accounts', function () {
    $c = AdPlatformConnection::factory()->create();
    AdAccount::factory()->create(['connection_id' => $c->id]);

    $this->actingAs(adsPgUser(UserRole::Admin))->delete("/ads/connections/{$c->id}")->assertRedirect();
    expect(AdPlatformConnection::count())->toBe(0)->and(AdAccount::count())->toBe(0);
});

it('manages buyers, targets and settings', function () {
    $admin = adsPgUser(UserRole::Admin);
    $u = adsPgUser(UserRole::MediaBuyer);

    $this->actingAs($admin)->post('/ads/setup/buyers', ['name' => 'Ahmed', 'color' => '#ff0000', 'user_id' => $u->id])->assertRedirect();
    $buyer = MediaBuyer::firstOrFail();
    expect($buyer->user_id)->toBe($u->id)->and($buyer->is_active)->toBeTrue();

    $this->actingAs($admin)->post('/ads/setup/buyers', ['name' => 'Dup', 'user_id' => $u->id])->assertSessionHasErrors('user_id');
    $this->actingAs($admin)->post('/ads/setup/buyers', ['name' => 'Bad', 'user_id' => adsPgUser(UserRole::Moderator)->id])->assertSessionHasErrors('user_id');

    $this->actingAs($admin)->put("/ads/setup/buyers/{$buyer->id}", ['name' => 'Ahmed G', 'user_id' => $u->id, 'is_active' => false])->assertRedirect();
    expect($buyer->refresh()->name)->toBe('Ahmed G')->and($buyer->is_active)->toBeFalse();

    $this->actingAs($admin)->put("/ads/setup/buyers/{$buyer->id}/targets", ['month' => '2026-09', 'budget' => 50000, 'target_roas' => 2.5])->assertRedirect();
    $this->actingAs($admin)->put("/ads/setup/buyers/{$buyer->id}/targets", ['month' => '2026-09', 'budget' => 60000, 'target_roas' => null])->assertRedirect();
    expect(BuyerTarget::count())->toBe(1)->and((float) BuyerTarget::first()->budget)->toBe(60000.0)->and(BuyerTarget::first()->month->toDateString())->toBe('2026-09-01');

    $this->actingAs($admin)->put('/ads/setup/settings', ['tax_rate_percent' => 12.5, 'winner_thresholds' => ['winner' => 3, 'min_days' => 4]])->assertRedirect();
    $this->actingAs($admin)->put('/ads/setup/settings', ['tax_rate_percent' => 101])->assertSessionHasErrors('tax_rate_percent');
    $this->actingAs($admin)->put('/ads/setup/settings', ['tax_rate_percent' => 10, 'winner_thresholds' => ['winner' => -1]])->assertSessionHasErrors('winner_thresholds.winner');

    $this->actingAs($admin)->get('/ads/setup/buyers')->assertInertia(fn (Assert $p) => $p
        ->component('Ads/BuyersSetup')
        ->where('buyers.0.user.id', $u->id)->where('buyers.0.targets.0.month', '2026-09')->where('buyers.0.targets.0.budget', 60000)
        ->where('buyers.0.targets.0.target_roas', null)
        ->has('users', 1)->where('users.0.role', 'media_buyer')
        ->where('settings.tax_rate', 0.125)->where('settings.tax_rate_percent', 12.5)
        ->where('settings.winner_thresholds.winner', 3)->where('settings.winner_thresholds.min_days', 4)->where('settings.winner_thresholds.promising', 1.3));
});

it('archives a buyer with history instead of deleting it', function () {
    $admin = adsPgUser(UserRole::Admin);
    $withHistory = MediaBuyer::factory()->create();
    app(AssignmentService::class)->assign(AdAccount::factory()->create(), $withHistory, CarbonImmutable::parse('2026-09-01'));
    $clean = MediaBuyer::factory()->create();

    $this->actingAs($admin)->delete("/ads/setup/buyers/{$withHistory->id}")->assertRedirect();
    $this->actingAs($admin)->delete("/ads/setup/buyers/{$clean->id}")->assertRedirect();

    expect($withHistory->refresh()->is_active)->toBeFalse()->and(MediaBuyer::find($clean->id))->toBeNull();
});

it('retries the errored empty connection instead of creating a twin', function () {
    Queue::fake();
    $admin = adsPgUser(UserRole::Admin);
    $driver = new class extends FakeAdsDriver
    {
        public bool $fail = true;

        public function accounts(AdPlatformConnection $c): array
        {
            return $this->fail ? throw new AdsApiException('Token expired') : parent::accounts($c);
        }
    };
    app()->bind(FakeAdsDriver::class, fn () => $driver);
    $payload = ['platform' => 'meta', 'name' => 'M', 'credentials' => ['access_token' => 'bad']];

    $this->actingAs($admin)->post('/ads/connections', $payload)->assertSessionHasErrors('credentials');
    $driver->fail = false;
    $this->actingAs($admin)->post('/ads/connections', ['name' => 'M2', 'credentials' => ['access_token' => 'good']] + $payload)->assertSessionHasNoErrors();

    $c = AdPlatformConnection::firstOrFail();
    expect(AdPlatformConnection::count())->toBe(1)
        ->and($c->name)->toBe('M2')->and($c->credentials['access_token'])->toBe('good')
        ->and($c->status)->toBe('connected')->and($c->last_error)->toBeNull()
        ->and($c->accounts()->count())->toBe(3);
});

it('clears a stale error when credentials change and saves thresholds without a tax rate', function () {
    $admin = adsPgUser(UserRole::Admin);
    $c = AdPlatformConnection::factory()->create(['status' => 'error', 'last_error' => 'Token expired']);

    $this->actingAs($admin)->put("/ads/connections/{$c->id}", ['name' => 'Same'])->assertRedirect();
    expect($c->refresh()->status)->toBe('error');
    $this->actingAs($admin)->put("/ads/connections/{$c->id}", ['credentials' => ['access_token' => 'fresh']])->assertRedirect();
    expect($c->refresh()->status)->toBe('pending')->and($c->last_error)->toBeNull();

    $this->actingAs($admin)->put('/ads/setup/settings', ['tax_rate_percent' => 20])->assertRedirect();
    $this->actingAs($admin)->put('/ads/setup/settings', ['winner_thresholds' => ['winner' => 4]])->assertRedirect();
    $s = app(AdsSettings::class);
    expect($s->taxRate())->toBe(0.2)->and($s->winnerThresholds()['winner'])->toBe(4);
});

it('names the credential field with its translated label in validation errors', function () {
    $r = $this->actingAs(adsPgUser(UserRole::Admin))->post('/ads/connections', ['platform' => 'meta', 'name' => 'M', 'credentials' => ['access_token' => '']]);
    $r->assertSessionHasErrors('credentials.access_token');
    expect(session('errors')->first('credentials.access_token'))->toContain(__('ads.credentials.access_token'));
});

it('never shows a media buyer another buyers numbers, ads or cards', function () {
    $w = adsPgBuyerWorld();
    $foreign = AdAccount::factory()->create(['name' => 'FOREIGN ACC']);
    app(AssignmentService::class)->assign($foreign, $w['other'], CarbonImmutable::now('Africa/Cairo')->subDays(5));
    $day = CarbonImmutable::now('Africa/Cairo')->subDay()->toDateString();
    $mine = Ad::factory()->for($w['account'], 'account')->create(['name' => 'MY AD']);
    $theirs = Ad::factory()->for($foreign, 'account')->create(['name' => 'FOREIGN AD']);
    foreach ([[$mine, 100], [$theirs, 7777]] as [$ad, $spend]) {
        AdDailyMetric::factory()->create([
            'ad_id' => $ad->id, 'ad_account_id' => $ad->ad_account_id, 'date' => $day, 'spend' => $spend,
            'impressions' => 1000, 'clicks' => 10, 'reach' => 900, 'purchases' => 5, 'purchase_value' => $spend * 3,
        ]);
    }
    $this->actingAs($w['user']);

    $overview = $this->get("/ads?buyer={$w['other']->id}&accounts[]={$foreign->id}")->assertOk();
    expect($overview->viewData('page')['props']['overview']['totals']['spend'])->toBe(0.0);
    expect($this->get('/ads')->viewData('page')['props']['overview']['totals']['spend'])->toBe(100.0);

    $creatives = $this->get("/ads/creatives?account={$foreign->id}&buyer={$w['other']->id}")->assertOk()->getContent();
    expect($creatives)->not->toContain('FOREIGN AD')->not->toContain('FOREIGN ACC');
    $own = $this->get('/ads/creatives')->getContent();
    expect($own)->toContain('MY AD')->not->toContain('FOREIGN AD');

    $winners = $this->get("/ads/winners?buyer={$w['other']->id}")->assertOk()->getContent();
    expect($winners)->not->toContain('FOREIGN AD');

    $this->get('/ads/buyers')->assertInertia(fn (Assert $p) => $p
        ->has('cards', 1)
        ->where('cards.0.buyer_id', $w['buyer']->id)->where('cards.0.spend', 100));
});
