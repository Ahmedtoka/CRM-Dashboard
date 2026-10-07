<?php

use App\Ads\Alerts\AlertData;
use App\Enums\Platform;
use App\Inbox\Outcomes\Commands\CloseIdleEpisodes;
use App\Inbox\Outcomes\OutcomeRecorder;
use App\Models\Conversation;
use App\Models\LoadTestRun;
use App\Models\Message;
use App\Simulator\LoadTest\LoadTest;
use App\Simulator\Simulator;
use App\TestLinks\TestScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * Review round 1: a load-test chat leaves every report, ads and Today count (it is a test for
 * them, like a team test link), but the flow still treats it as real: rating, idle sweep, state chip.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 12:00', 'Africa/Cairo'));
    config(['crm.load_test' => true, 'crm.drivers.channels' => 'live']); // a production server: load-test chats are tests
    Http::fake();
});

function reChat(string $key = 're-1'): Conversation
{
    $tag = app(LoadTest::class)->tag(LoadTestRun::activeOrStart(), 'price', 'هبة', $key);

    return app(Simulator::class)->customerMessage(Platform::Instagram, $key, 'هبة', 'بكام؟', loadTest: $tag)->conversation->fresh();
}

it('flags the chat and its messages as test rows, so the reports leave them out', function () {
    $c = reChat();
    $real = Conversation::factory()->create();

    expect($c->is_test)->toBeTrue()
        ->and(Message::query()->where('conversation_id', $c->id)->pluck('is_test')->unique()->all())->toBe([true])
        ->and(TestScope::realConversations(Conversation::query())->pluck('id')->all())->toBe([$real->id])
        ->and(TestScope::realConversations(DB::table('conversations as c'), 'c')->pluck('id')->all())->toBe([$real->id]);
});

it('leaves load-test chats out of the ad alert chat counts', function () {
    $c = reChat();
    $real = Conversation::factory()->create();
    foreach ([$c, $real] as $conv) {
        DB::table('conversation_ad_referrals')->insert(['conversation_id' => $conv->id, 'customer_id' => $conv->customer_id, 'ad_external_id' => 'AD-1', 'referred_at' => now()]);
    }

    expect(app(AlertData::class)->cohortChats(['AD-1'], '2026-10-07', '2026-10-07'))->toBe(['AD-1' => 1]);
});

it('still sweeps an idle load-test chat like a real one', function () {
    config(['crm.outcomes.idle_hours' => 1]);
    $c = reChat();
    Carbon::setTestNow(now()->addHours(3));

    $ids = (new CloseIdleEpisodes)->candidates(1, app(OutcomeRecorder::class))->pluck('id')->all();

    expect($ids)->toContain($c->id);
});

it('counts the simulated traffic on a fake-driver demo install (the local demo data)', function () {
    config(['crm.drivers.channels' => 'fake']);

    expect(reChat('re-demo')->is_test)->toBeFalse();
});
