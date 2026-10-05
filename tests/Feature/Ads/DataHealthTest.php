<?php

use App\Ads\AdsSettings;
use App\Ads\Health\DataHealth;
use App\Ads\Health\QueueHeartbeat;
use App\Enums\OrderSource;
use App\Enums\UserRole;
use App\Mail\AdsSystemAlert;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdAccountDaily;
use App\Models\AdDailyMetric;
use App\Models\AdPlatformConnection;
use App\Models\AdsApiUsage;
use App\Models\AdsHealthState;
use App\Models\AdsSyncRun;
use App\Models\Conversation;
use App\Models\MediaBuyer;
use App\Models\Order;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    config(['crm.ads.sync.queue' => 'commercelong']);
});

/** Every global heartbeat fresh at the (possibly time-travelled) now, so only the account checks speak. */
function dhBeats(): void
{
    Cache::forever('crm:scheduler_heartbeat', now()->toISOString());
    foreach (['default', 'commercelong'] as $q) {
        (new QueueHeartbeat($q))->handle(app(AdsSettings::class));
    }
}

function dhAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
}

function dhAccount(?int $okAgoMinutes = 10): AdAccount
{
    $c = AdPlatformConnection::factory()->meta()->create(['status' => 'connected']);
    $a = AdAccount::factory()->meta()->create(['connection_id' => $c->id]);
    if ($okAgoMinutes !== null) {
        AdsSyncRun::factory()->create(['ad_account_id' => $a->id, 'status' => 'ok', 'started_at' => now()->subMinutes($okAgoMinutes + 1), 'finished_at' => now()->subMinutes($okAgoMinutes)]);
    }

    return $a;
}

function dhRun(): void
{
    dhBeats();
    Artisan::call('ads:health');
}

function dhStatus(string $key): ?string
{
    return AdsHealthState::where('key', $key)->value('status');
}

it('records warn at 3 h, notifies each admin once after the 30 minute hold-down and never emails a warning', function () {
    $admin = dhAdmin();
    $admin2 = dhAdmin();
    $buyerUser = User::factory()->create(['role' => UserRole::MediaBuyer, 'is_active' => true]);
    $a = dhAccount(4 * 60);

    dhRun();
    expect(dhStatus("stale:{$a->id}"))->toBe('warn')->and(UserNotification::where('type', 'ads.data_health')->count())->toBe(0);

    $this->travel(29)->minutes();
    dhRun();
    expect(UserNotification::where('type', 'ads.data_health')->count())->toBe(0);

    $this->travel(2)->minutes();
    dhRun();
    expect(UserNotification::where('type', 'ads.data_health')->where('user_id', $admin->id)->count())->toBe(1)
        ->and(UserNotification::where('type', 'ads.data_health')->where('user_id', $admin2->id)->count())->toBe(1)
        ->and(UserNotification::where('user_id', $buyerUser->id)->count())->toBe(0);
    $n = UserNotification::where('type', 'ads.data_health')->first();
    expect($n->data['reason'])->toBe('stale')->and($n->data['account'])->toBe($a->name)->and($n->data['status'])->toBe('warn');
    Mail::assertNothingSent();

    dhRun(); // same state again: nothing new
    expect(UserNotification::where('type', 'ads.data_health')->where('user_id', $admin->id)->count())->toBe(1);
});

it('goes critical past 6 h, emails the admins once and sends one recovery notice when the sync returns', function () {
    $admin = dhAdmin();
    $a = dhAccount(6 * 60 + 31);

    dhRun();
    expect(dhStatus("stale:{$a->id}"))->toBe('critical');
    Mail::assertNothingSent();

    $this->travel(31)->minutes();
    dhRun();
    expect(UserNotification::where('type', 'ads.data_health')->where('user_id', $admin->id)->count())->toBe(1);
    Mail::assertSent(AdsSystemAlert::class, 1);
    Mail::assertSent(AdsSystemAlert::class, fn ($m) => $m->hasTo($admin->email));

    dhRun();
    Mail::assertSent(AdsSystemAlert::class, 1);

    AdsSyncRun::factory()->create(['ad_account_id' => $a->id, 'status' => 'ok', 'finished_at' => now()]);
    dhRun();
    expect(dhStatus("stale:{$a->id}"))->toBe('ok')->and(UserNotification::where('type', 'ads.data_health')->count())->toBe(1);

    $this->travel(31)->minutes();
    dhRun();
    $recovered = UserNotification::where('type', 'ads.data_health')->orderBy('id')->get();
    expect($recovered)->toHaveCount(2)->and($recovered->last()->data['recovered'])->toBeTrue();
    Mail::assertSent(AdsSystemAlert::class, 1);
});

it('does not notify when a check flaps faster than the hold-down', function () {
    dhAdmin();
    $a = dhAccount(10);

    foreach (range(1, 6) as $i) {
        // bad on even steps, good on odd steps, one step every 10 minutes
        AdsSyncRun::query()->where('ad_account_id', $a->id)->delete();
        AdsSyncRun::factory()->create(['ad_account_id' => $a->id, 'status' => 'ok', 'finished_at' => now()->subMinutes($i % 2 === 0 ? 4 * 60 : 5)]);
        dhRun();
        $this->travel(10)->minutes();
    }

    expect(UserNotification::where('type', 'ads.data_health')->count())->toBe(0);
    Mail::assertNothingSent();
});

it('flags a scheduler heartbeat older than 5 minutes as critical and emails after the hold-down', function () {
    $admin = dhAdmin();

    Cache::forever('crm:scheduler_heartbeat', now()->subMinutes(6)->toISOString());
    (new QueueHeartbeat('default'))->handle(app(AdsSettings::class));
    (new QueueHeartbeat('commercelong'))->handle(app(AdsSettings::class));
    Artisan::call('ads:health');
    expect(dhStatus('scheduler'))->toBe('critical');

    $this->travel(31)->minutes();
    Cache::forever('crm:scheduler_heartbeat', now()->subMinutes(6)->toISOString());
    (new QueueHeartbeat('default'))->handle(app(AdsSettings::class));
    (new QueueHeartbeat('commercelong'))->handle(app(AdsSettings::class));
    Artisan::call('ads:health');

    Mail::assertSent(AdsSystemAlert::class, 1);
    expect(UserNotification::where('type', 'ads.data_health')->where('user_id', $admin->id)->count())->toBe(1);
});

it('flags a queue whose heartbeat is older than 15 minutes', function () {
    dhRun(); // the monitor starts now: a queue that never beat is counted from here
    $this->travel(16)->minutes();
    Cache::forever('crm:scheduler_heartbeat', now()->toISOString());
    (new QueueHeartbeat('default'))->handle(app(AdsSettings::class));

    Artisan::call('ads:health');

    expect(dhStatus('queue:default'))->toBe('ok')->and(dhStatus('queue:commercelong'))->toBe('critical');
});

it('warns on overlapping open assignments and keeps the accounts in the detail', function () {
    $a = dhAccount();
    $b1 = MediaBuyer::factory()->create();
    $b2 = MediaBuyer::factory()->create();
    AdAccountAssignment::create(['ad_account_id' => $a->id, 'media_buyer_id' => $b1->id, 'starts_on' => '2026-09-01']);
    AdAccountAssignment::create(['ad_account_id' => $a->id, 'media_buyer_id' => $b2->id, 'starts_on' => '2026-09-02']);

    dhRun();

    expect(dhStatus('assignments_overlap'))->toBe('warn')
        ->and(AdsHealthState::where('key', 'assignments_overlap')->first()->detail['account_ids'])->toBe([$a->id]);
});

it('reports reconnect, read-only and a control gap per account', function () {
    $a = dhAccount();
    $a->connection->update(['status' => 'needs_reconnect']);
    $b = dhAccount();
    $b->connection->forceFill(['read_only' => true])->save();
    $g = dhAccount();
    $day = CarbonImmutable::now('Africa/Cairo')->subDay()->toDateString();
    AdAccountDaily::create(['ad_account_id' => $g->id, 'date' => $day, 'spend' => 1000, 'fetched_at' => now()]);
    AdDailyMetric::factory()->create(['ad_id' => Ad::factory()->for($g, 'account')->create()->id, 'ad_account_id' => $g->id, 'date' => $day, 'spend' => 900]);

    dhRun();

    expect(dhStatus("reconnect:{$a->id}"))->toBe('critical')->and(dhStatus("reconnect:{$b->id}"))->toBe('ok')
        ->and(dhStatus("read_only:{$b->id}"))->toBe('warn')->and(dhStatus("read_only:{$a->id}"))->toBe('ok')
        ->and(dhStatus("control_gap:{$g->id}"))->toBe('warn')->and(dhStatus("control_gap:{$a->id}"))->toBe('ok')
        ->and(AdsHealthState::where('key', "control_gap:{$g->id}")->first()->detail['gap_pct'])->toEqual(10);
});

it('sends one email and one notice per admin for the whole run, listing every account', function () {
    $admin = dhAdmin();
    foreach (range(1, 4) as $i) {
        $a = dhAccount();
        $a->update(['name' => "Shop {$i}"]);
        $a->connection->update(['status' => 'needs_reconnect']);
    }

    dhRun();
    $this->travel(31)->minutes();
    dhRun();

    Mail::assertSent(AdsSystemAlert::class, 1);
    Mail::assertSent(AdsSystemAlert::class, fn ($m) => count($m->lines) === 4 && collect($m->lines)->pluck('subject')->sort()->values()->all() === ['Shop 1', 'Shop 2', 'Shop 3', 'Shop 4']);
    $notices = UserNotification::where('type', 'ads.data_health')->where('user_id', $admin->id)->get();
    expect($notices)->toHaveCount(1)->and($notices->first()->data['more'])->toBe(3)->and($notices->first()->data['lines'])->toHaveCount(4);
});

it('keeps a Meta usage reading under the refusal point off the notices but shows it', function () {
    dhAdmin();
    $a = dhAccount();
    AdsApiUsage::create(['ad_account_id' => $a->id, 'header' => 'x-ad-account-usage', 'max_pct' => 78, 'recorded_at' => now()]);

    dhRun();
    $this->travel(31)->minutes();
    AdsApiUsage::create(['ad_account_id' => $a->id, 'header' => 'x-ad-account-usage', 'max_pct' => 78, 'recorded_at' => now()]);
    dhRun();
    expect(dhStatus("usage:{$a->id}"))->toBe('warn')->and(UserNotification::where('type', 'ads.data_health')->count())->toBe(0);

    AdsApiUsage::create(['ad_account_id' => $a->id, 'header' => 'x-ad-account-usage', 'max_pct' => 90, 'recorded_at' => now()]);
    dhRun();
    expect(UserNotification::where('type', 'ads.data_health')->count())->toBe(1);
});

it('announces as resolved a bad check whose account is no longer checked', function () {
    dhAdmin();
    $a = dhAccount(4 * 60);
    dhRun();
    $this->travel(31)->minutes();
    dhRun();
    expect(UserNotification::where('type', 'ads.data_health')->count())->toBe(1);

    $a->update(['is_active' => false]);
    dhRun();

    $n = UserNotification::where('type', 'ads.data_health')->orderBy('id')->get();
    expect($n)->toHaveCount(2)->and($n->last()->data['recovered'])->toBeTrue()->and($n->last()->data['account'])->toBe($a->name)
        ->and(dhStatus("stale:{$a->id}"))->toBeNull();
});

it('keeps ads:health at exit 0 and still sends the in-app notice when the mailer throws', function () {
    $admin = dhAdmin();
    $a = dhAccount();
    $a->connection->update(['status' => 'needs_reconnect']);
    dhRun();
    $this->travel(31)->minutes();

    $mailer = Mockery::mock();
    $mailer->shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
    Mail::swap($mailer);
    dhBeats();

    expect(Artisan::call('ads:health'))->toBe(0)
        ->and(UserNotification::where('type', 'ads.data_health')->where('user_id', $admin->id)->count())->toBe(1);
});

function dhOrder(OrderSource $source, bool $linked, int $daysAgo): void
{
    $o = Order::factory()->create(['source' => $source]);
    DB::table('orders')->where('id', $o->id)->update([
        'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
        'conversation_id' => $linked ? Conversation::factory()->create()->id : null,
    ]);
}

it('computes the chat order link rate without ever notifying', function () {
    $admin = dhAdmin();
    foreach ([2, 2, 2] as $d) {
        dhOrder(OrderSource::Chat, true, $d);
    }
    dhOrder(OrderSource::Chat, false, 3);
    dhOrder(OrderSource::Store, false, 1);   // not a chat order
    dhOrder(OrderSource::Chat, false, 30);   // older than 14 days

    dhRun();
    $this->travel(2)->hours();
    dhRun();

    $detail = AdsHealthState::where('key', 'link_rate')->first()->detail;
    expect($detail['rate'])->toBe(0.75)->and($detail['orders'])->toBe(4)->and($detail['linked'])->toBe(3)
        ->and(UserNotification::where('user_id', $admin->id)->where('type', 'ads.data_health')->count())->toBe(0);
});

it('drops state rows of accounts that are no longer checked', function () {
    $a = dhAccount(4 * 60);
    dhRun();
    expect(dhStatus("stale:{$a->id}"))->toBe('warn');

    $a->update(['is_active' => false]);
    dhRun();

    expect(dhStatus("stale:{$a->id}"))->toBeNull();
});

it('evaluates without a state table write', function () {
    $a = dhAccount(4 * 60);
    dhBeats();

    $checks = app(DataHealth::class)->evaluate();

    expect(collect($checks)->firstWhere('key', "stale:{$a->id}")->status)->toBe('warn')->and(AdsHealthState::count())->toBe(0);
});
