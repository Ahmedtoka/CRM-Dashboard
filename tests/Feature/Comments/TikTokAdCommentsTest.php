<?php

use App\Channels\Adapters\FakeChannelAdapter;
use App\Channels\Adapters\TikTokAdapter;
use App\Channels\ChannelRegistry;
use App\Comments\CommentActions;
use App\Comments\Jobs\PollTikTokAdComments;
use App\Comments\Jobs\RunCommentBot;
use App\Comments\PrivateReplyNotAllowedException;
use App\Comments\TikTok\TikTokAdCommentPoller;
use App\Enums\CommentStatus;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdPlatformConnection;
use App\Models\AdSet;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    Event::fake();
    Cache::flush();
    FakeChannelAdapter::reset();
    config(['crm.ads.drivers.tiktok' => 'live', 'crm.tiktok_ad_comments.enabled' => true]);
    $this->travelTo(now()->setTimezone('Africa/Cairo')->setTime(12, 0));
});

function ttcAccount(): AdAccount
{
    $c = AdPlatformConnection::factory()->tiktok()->create(['credentials' => ['access_token' => 'tt-secret', 'advertiser_ids' => ['7001']]]);
    $a = AdAccount::factory()->tiktok()->create(['external_id' => '7001', 'name' => 'Le Voile TT', 'connection_id' => $c->id, 'timezone' => 'Africa/Cairo']);
    $campaign = AdCampaign::factory()->create(['ad_account_id' => $a->id, 'external_id' => '501', 'name' => 'Sales', 'status' => 'ENABLE']);
    $set = AdSet::factory()->create(['ad_campaign_id' => $campaign->id, 'external_id' => '9001', 'name' => 'Women', 'status' => 'ENABLE']);
    AdSet::factory()->create(['ad_campaign_id' => $campaign->id, 'external_id' => '9002', 'name' => 'Paused', 'status' => 'DISABLE']);
    Ad::factory()->for($a, 'account')->create(['external_id' => '4401', 'name' => 'Abaya video', 'ad_campaign_id' => $campaign->id, 'ad_set_id' => $set->id]);

    return $a;
}

/** One /comment/list/ row as documented (Marketing API v1.3 «Get comments»). */
function ttcRow(array $over = []): array
{
    return $over + [
        'comment_id' => '7300000000000000001', 'content' => 'بكام دي؟', 'comment_type' => 'COMMENT', 'original_comment_id' => null,
        'comment_status' => 'PUBLIC', 'ad_id' => '4401', 'adgroup_id' => '9001', 'campaign_id' => '501', 'tiktok_item_id' => '7299000000000000001',
        'identity_id' => 'id77', 'identity_type' => 'CUSTOMIZED_USER', 'user_id' => '6800000000000000001', 'user_name' => 'mona',
        'create_time' => now()->utc()->subMinutes(10)->format('Y-m-d H:i:s'), 'hit_blockedword' => false,
    ];
}

/**
 * TikTok fake. Http::fake stubs stack and the first match wins, so a test changes what TikTok answers through the
 * shared $GLOBALS rows / list code instead of a second fake.
 */
function ttcFakeList(array $rows): void
{
    $GLOBALS['ttc_rows'] = $rows;
    $GLOBALS['ttc_list_code'] = 0;
    Http::fake(function (Request $r) {
        $url = $r->url();
        if (str_contains($url, 'comment/list/')) {
            $code = $GLOBALS['ttc_list_code'];

            return Http::response($code !== 0
                ? ['code' => $code, 'message' => $code === 40100 ? 'Too many requests' : 'No permission', 'data' => []]
                : ['code' => 0, 'message' => 'OK', 'data' => ['list' => $GLOBALS['ttc_rows'], 'page_info' => ['page' => 1, 'total_page' => 1]]]);
        }
        if (str_contains($url, 'comment/post/')) {
            return Http::response(['code' => 0, 'message' => 'OK', 'data' => ['comment_id' => '7300000000000000999']]);
        }
        if (str_contains($url, 'comment/status/update/')) {
            return Http::response(['code' => 0, 'message' => 'OK', 'data' => []]);
        }

        return Http::response(['code' => 50000, 'message' => 'unexpected call', 'data' => []]);
    });
}

it('polls each active ad group for today and yesterday and ingests comments with the ad attached', function () {
    Queue::fake();
    BotSetting::current()->update(['enabled' => true]);
    $a = ttcAccount();
    ttcFakeList([ttcRow()]);

    $r = app(TikTokAdCommentPoller::class)->poll($a);

    expect($r['adgroups'])->toBe(1)->and($r['ingested'])->toBe(1);
    $c = Comment::with('post.channelAccount', 'customer')->sole();
    expect($c->external_id)->toBe('7300000000000000001')->and($c->body)->toBe('بكام دي؟')->and($c->status)->toBe(CommentStatus::New)
        ->and($c->post->platform)->toBe(Platform::TikTok)->and($c->post->is_ad)->toBeTrue()
        ->and($c->post->ad_id)->toBe('4401')->and($c->post->ad_title)->toBe('Abaya video')->and($c->post->ad_campaign_name)->toBe('Sales')
        ->and($c->post->platform_meta)->toMatchArray(['advertiser_id' => '7001', 'ad_id' => '4401', 'tiktok_item_id' => '7299000000000000001', 'identity_id' => 'id77', 'identity_type' => 'CUSTOMIZED_USER'])
        ->and($c->post->channelAccount->platform)->toBe(Platform::TikTok)->and($c->post->channelAccount->external_id)->toBe('7001')
        ->and($c->customer->name)->toBe('mona');
    Queue::assertPushed(RunCommentBot::class);

    Http::assertSent(function (Request $r) {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return $r->hasHeader('Access-Token', 'tt-secret') && $q['search_field'] === 'ADGROUP_ID' && $q['search_value'] === '9001'
            && $q['start_time'] === now('Africa/Cairo')->subDay()->toDateString() && $q['end_time'] === now('Africa/Cairo')->toDateString()
            && $q['page_size'] === '100' && $q['advertiser_id'] === '7001';
    });
    Http::assertSentCount(1); // the paused ad group is not polled
});

it('ingests a comment once across polls and follows a hide made in TikTok', function () {
    Queue::fake();
    $a = ttcAccount();
    ttcFakeList([ttcRow()]);
    app(TikTokAdCommentPoller::class)->poll($a);

    $GLOBALS['ttc_rows'] = [ttcRow(['comment_status' => 'HIDDEN'])];
    $this->travel(11)->minutes(); // past the window that absorbs list lag after a CRM change
    $r = app(TikTokAdCommentPoller::class)->poll($a);

    expect(Comment::count())->toBe(1)->and($r['ingested'])->toBe(0)->and($r['updated'])->toBe(1)
        ->and(Comment::sole()->status)->toBe(CommentStatus::Hidden);
});

it('keeps a reply in a thread linked to its parent and never answers it automatically', function () {
    Queue::fake();
    $a = ttcAccount();
    ttcFakeList([ttcRow(['comment_id' => '7300000000000000002', 'comment_type' => 'REPLY', 'original_comment_id' => '7300000000000000001'])]);

    app(TikTokAdCommentPoller::class)->poll($a);

    $c = Comment::sole();
    expect($c->parent_external_id)->toBe('7300000000000000001');
    BotSetting::current()->update(['enabled' => true]);
    expect(app(\App\Comments\CommentBot::class)->handle($c))->toBeNull();
});

it('does not run the bot on old comments read by the first poll', function () {
    Queue::fake();
    BotSetting::current()->update(['enabled' => true]);
    $a = ttcAccount();
    ttcFakeList([ttcRow(['create_time' => now()->utc()->subHours(20)->format('Y-m-d H:i:s')])]);

    app(TikTokAdCommentPoller::class)->poll($a);

    expect(Comment::count())->toBe(1);
    Queue::assertNotPushed(RunCommentBot::class);
});

it('stops on a throttle and waits at least 5 minutes before polling that account again', function () {
    $a = ttcAccount();
    ttcFakeList([]);
    $GLOBALS['ttc_list_code'] = 40100;

    expect(app(TikTokAdCommentPoller::class)->poll($a)['skipped'])->toBe('rate_limited');
    $this->travel(4)->minutes();
    expect(app(TikTokAdCommentPoller::class)->poll($a)['skipped'])->toBe('cooldown');
    $this->travel(2)->minutes();
    $GLOBALS['ttc_list_code'] = 0;
    expect(app(TikTokAdCommentPoller::class)->poll($a)['skipped'])->toBeNull();
});

it('stops quietly when the comment permission is missing', function () {
    $a = ttcAccount();
    ttcFakeList([]);
    $GLOBALS['ttc_list_code'] = 40001;

    expect(app(TikTokAdCommentPoller::class)->poll($a)['skipped'])->toBe('missing_permission')->and(Comment::count())->toBe(0);
});

it('caps the ad groups per run and rotates the least recently polled first', function () {
    config(['crm.tiktok_ad_comments.max_adgroups_per_run' => 1]);
    $a = ttcAccount();
    AdSet::factory()->create(['ad_campaign_id' => AdCampaign::where('external_id', '501')->value('id'), 'external_id' => '9003', 'status' => 'ENABLE']);
    ttcFakeList([]);

    app(TikTokAdCommentPoller::class)->poll($a);
    $this->travel(3)->minutes();
    app(TikTokAdCommentPoller::class)->poll($a);

    $polled = collect(Http::recorded())->map(function ($p) {
        parse_str((string) parse_url($p[0]->url(), PHP_URL_QUERY), $q);

        return $q['search_value'];
    })->all();
    expect($polled)->toBe(['9001', '9003']);
});

it('replies under a first-level comment as the ad identity and never ingests our own reply', function () {
    config(['crm.drivers.channels' => 'live']);
    Queue::fake();
    $a = ttcAccount();
    ttcFakeList([ttcRow()]);
    app(TikTokAdCommentPoller::class)->poll($a);
    $c = Comment::sole();
    expect(app(ChannelRegistry::class)->adapterFor($c->post->channelAccount))->toBeInstanceOf(TikTokAdapter::class);

    app(CommentActions::class)->reply($c, 'السعر في الرسايل', User::factory()->create(['role' => UserRole::Admin]));

    expect($c->fresh()->status)->toBe(CommentStatus::Replied)->and($c->fresh()->public_reply_external_id)->toBe('7300000000000000999');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'comment/post/') && $r->data() === [
        'advertiser_id' => '7001', 'ad_id' => '4401', 'tiktok_item_id' => '7299000000000000001', 'comment_id' => '7300000000000000001',
        'comment_type' => 'REPLY', 'text' => 'السعر في الرسايل', 'identity_type' => 'CUSTOMIZED_USER', 'identity_id' => 'id77',
    ]);

    // The next poll lists our reply back: it is skipped.
    $GLOBALS['ttc_rows'] = [ttcRow(), ttcRow(['comment_id' => '7300000000000000999', 'comment_type' => 'REPLY', 'original_comment_id' => '7300000000000000001', 'user_id' => ''])];
    $this->travel(5)->minutes();
    $r = app(TikTokAdCommentPoller::class)->poll($a);
    expect(Comment::count())->toBe(1)->and($r['adgroups'])->toBe(1)->and($r['ingested'])->toBe(0);
});

it('refuses to reply to a reply in a thread with a clear message', function () {
    config(['crm.drivers.channels' => 'live']);
    Queue::fake();
    $a = ttcAccount();
    ttcFakeList([ttcRow(['comment_id' => '7300000000000000002', 'comment_type' => 'REPLY', 'original_comment_id' => '7300000000000000001'])]);
    app(TikTokAdCommentPoller::class)->poll($a);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->postJson('/comments/'.Comment::sole()->id.'/reply', ['text' => 'hi'])
        ->assertStatus(502)->assertJsonPath('message', TikTokAdapter::TOP_LEVEL_ONLY);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'comment/post/'));
});

it('hides and unhides a TikTok ad comment from the comments page', function () {
    config(['crm.drivers.channels' => 'live']);
    Queue::fake();
    $a = ttcAccount();
    ttcFakeList([ttcRow()]);
    app(TikTokAdCommentPoller::class)->poll($a);
    $c = Comment::sole();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->postJson("/comments/{$c->id}/hide")->assertOk()->assertJsonPath('data.status', 'hidden');
    $this->actingAs($admin)->postJson("/comments/{$c->id}/unhide")->assertOk()->assertJsonPath('data.status', 'new');

    $ops = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_contains($r->url(), 'comment/status/update/'))
        ->map(fn ($r) => [$r->data()['operation'], $r->data()['comment_ids']])->values()->all();
    expect($ops)->toBe([['HIDDEN', ['7300000000000000001']], ['PUBLIC', ['7300000000000000001']]]);
});

it('always refuses a private reply on TikTok with a clear message', function () {
    config(['crm.drivers.channels' => 'live']);
    Queue::fake();
    $a = ttcAccount();
    ttcFakeList([ttcRow()]);
    app(TikTokAdCommentPoller::class)->poll($a);

    expect(fn () => app(CommentActions::class)->privateReply(Comment::sole(), 'inbox', null))
        ->toThrow(PrivateReplyNotAllowedException::class, TikTokAdapter::PRIVATE_REPLY_REFUSED);
    expect(app(TikTokAdapter::class)->sendPrivateReply(ChannelAccount::sole(), '1', 'x')->success)->toBeFalse();
});

it('the unhide endpoint is refused on a platform that cannot unhide', function () {
    config(['crm.drivers.channels' => 'live']);
    $post = Post::factory()->for(ChannelAccount::factory()->state(['platform' => Platform::Facebook, 'driver' => 'live']), 'channelAccount')->create(['platform' => Platform::Facebook]);
    $c = Comment::factory()->for($post)->create(['status' => CommentStatus::Hidden]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->postJson("/comments/{$c->id}/unhide")->assertStatus(502);
    expect($c->fresh()->status)->toBe(CommentStatus::Hidden);
});

it('the command does nothing unless the TikTok ads driver is live and dispatches one job per account', function () {
    Queue::fake();
    ttcAccount();

    config(['crm.ads.drivers.tiktok' => 'fake']);
    $this->artisan('tiktok:poll-ad-comments')->assertSuccessful();
    Queue::assertNothingPushed();

    config(['crm.ads.drivers.tiktok' => 'live']);
    $this->artisan('tiktok:poll-ad-comments')->assertSuccessful();
    Queue::assertPushed(PollTikTokAdComments::class, 1);

    config(['crm.tiktok_ad_comments.enabled' => false]);
    Queue::fake();
    $this->artisan('tiktok:poll-ad-comments')->assertSuccessful();
    Queue::assertNothingPushed();
});

it('is scheduled every 3 minutes', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'tiktok:poll-ad-comments'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('*/3 * * * *');
});

it('ingests a comment on an ad the Ads Hub has not synced yet (ad id from TikTok)', function () {
    Queue::fake();
    $a = ttcAccount();
    ttcFakeList([ttcRow(['ad_id' => '4499', 'comment_id' => '7300000000000000005'])]);

    app(TikTokAdCommentPoller::class)->poll($a);

    $post = Comment::sole()->post;
    expect($post->external_id)->toBe('ad-4499')->and($post->ad_id)->toBe('4499')->and($post->is_ad)->toBeTrue()->and($post->ad_title)->toBeNull();
});

it('never ingests an earlier reply of ours after a second reply, nor one TikTok named no id for', function () {
    config(['crm.drivers.channels' => 'live']);
    Queue::fake();
    $a = ttcAccount();
    ttcFakeList([ttcRow()]);
    app(TikTokAdCommentPoller::class)->poll($a);
    $c = Comment::sole();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    app(CommentActions::class)->reply($c, 'أول رد', $admin);
    // Second reply: TikTok gives another id (the fake always answers ...999, so simulate the column moving on).
    app(CommentActions::class)->reply($c->fresh(), 'تاني رد', $admin);
    $c->forceFill(['public_reply_external_id' => '7300000000000000777'])->save();

    $GLOBALS['ttc_rows'] = [
        ttcRow(),
        ttcRow(['comment_id' => '7300000000000000999', 'comment_type' => 'REPLY', 'original_comment_id' => '7300000000000000001', 'content' => 'أول رد']),
        ttcRow(['comment_id' => '7300000000000000888', 'comment_type' => 'REPLY', 'original_comment_id' => '7300000000000000001', 'content' => 'تاني رد']),
    ];
    $this->travel(5)->minutes();
    app(TikTokAdCommentPoller::class)->poll($a);

    expect(Comment::count())->toBe(1);
});

it('keeps a CRM hide when the row has no status field or TikTok still lists it public right after', function () {
    config(['crm.drivers.channels' => 'live']);
    Queue::fake();
    $a = ttcAccount();
    ttcFakeList([ttcRow()]);
    app(TikTokAdCommentPoller::class)->poll($a);
    $c = Comment::sole();
    app(CommentActions::class)->hide($c, null);

    $row = ttcRow();
    unset($row['comment_status']);
    $GLOBALS['ttc_rows'] = [$row];
    $this->travel(3)->minutes();
    app(TikTokAdCommentPoller::class)->poll($a);
    expect($c->fresh()->status)->toBe(CommentStatus::Hidden);

    $GLOBALS['ttc_rows'] = [ttcRow()]; // PUBLIC, but within 10 minutes of our hide
    $this->travel(3)->minutes();
    app(TikTokAdCommentPoller::class)->poll($a);
    expect($c->fresh()->status)->toBe(CommentStatus::Hidden);
});

it('stays off TikTok while any caller is throttled, and stops a run on an unknown error', function () {
    $a = ttcAccount();
    ttcFakeList([]);
    Cache::put(\App\Ads\Platforms\TikTok\TikTokApi::THROTTLED_UNTIL_KEY, now()->addMinutes(5)->getTimestamp(), 600);

    expect(app(TikTokAdCommentPoller::class)->poll($a)['skipped'])->toBe('throttled');
    Http::assertNothingSent();

    Cache::forget(\App\Ads\Platforms\TikTok\TikTokApi::THROTTLED_UNTIL_KEY);
    AdSet::factory()->create(['ad_campaign_id' => AdCampaign::where('external_id', '501')->value('id'), 'external_id' => '9003', 'status' => 'ENABLE']);
    $GLOBALS['ttc_list_code'] = 40105;
    expect(app(TikTokAdCommentPoller::class)->poll($a)['skipped'])->toBe('error');
    Http::assertSentCount(1);
});

it('does not run the bot when TikTok sends no readable time', function () {
    Queue::fake();
    BotSetting::current()->update(['enabled' => true]);
    $a = ttcAccount();
    ttcFakeList([ttcRow(['create_time' => 'not a time'])]);

    app(TikTokAdCommentPoller::class)->poll($a);

    expect(Comment::count())->toBe(1);
    Queue::assertNotPushed(RunCommentBot::class);
});

it('a demo fake TikTok channel stays on the fake adapter next to the live one, and a disconnected live channel sends nothing', function () {
    config(['crm.drivers.channels' => 'live']);
    Queue::fake();
    $fake = ChannelAccount::factory()->create(['platform' => Platform::TikTok, 'driver' => 'fake', 'external_id' => 'demo-tt']);
    $a = ttcAccount();
    ttcFakeList([ttcRow()]);
    app(TikTokAdCommentPoller::class)->poll($a);
    $registry = app(ChannelRegistry::class);
    $live = ChannelAccount::where('external_id', '7001')->sole();

    expect($registry->adapterFor($fake))->toBeInstanceOf(FakeChannelAdapter::class)
        ->and($registry->adapterFor($live))->toBeInstanceOf(TikTokAdapter::class);

    $live->update(['status' => 'disconnected']);
    expect($registry->adapterFor($live->fresh()))->toBeInstanceOf(TikTokAdapter::class);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->postJson('/comments/'.Comment::sole()->id.'/hide')->assertStatus(502);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'comment/status/update/'));
});

it('keeps a CRM unhide when TikTok still lists the comment hidden right after', function () {
    config(['crm.drivers.channels' => 'live']);
    Queue::fake();
    $a = ttcAccount();
    ttcFakeList([ttcRow(['comment_status' => 'HIDDEN'])]);
    app(TikTokAdCommentPoller::class)->poll($a);
    $c = Comment::sole();
    expect($c->status)->toBe(CommentStatus::Hidden);
    $this->travel(11)->minutes();
    app(CommentActions::class)->unhide($c, null);

    $this->travel(3)->minutes();
    app(TikTokAdCommentPoller::class)->poll($a);

    expect($c->fresh()->status)->toBe(CommentStatus::New);
});
