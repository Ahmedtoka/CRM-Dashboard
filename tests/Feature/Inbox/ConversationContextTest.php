<?php

use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdCampaign;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Message;
use App\Models\Order;
use App\Models\QueueEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-08 12:00', 'Africa/Cairo')));

function s3CtxChat(array $attrs = []): Conversation
{
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    $c = Conversation::factory()->for($acc, 'channelAccount')->create($attrs + ['platform' => Platform::Facebook]);
    Message::factory()->for($c)->create(['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer]);

    return $c;
}

it('serves the outcome state: nothing automatic, then ordered', function () {
    $c = s3CtxChat();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->getJson("/inbox/conversations/{$c->id}/context")->assertOk()
        ->assertJsonPath('data.outcome', ['current' => null, 'source' => null, 'auto' => null]);

    Order::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'status' => 'confirmed']);
    $this->actingAs($admin)->getJson("/inbox/conversations/{$c->id}/context")->assertJsonPath('data.outcome.auto', 'ordered');
});

it('parses the bot handover note into a full digest', function () {
    $c = s3CtxChat(['handover_topic' => 'سؤال عن المقاس']);
    ConversationNote::factory()->create(['conversation_id' => $c->id, 'user_id' => null, 'body' => implode("\n", [
        'تحويل من البوت', 'السبب: سؤال عن المقاس', 'التصنيف: المقاسات', 'المنتجات: اسدال كتان، عباية سادة',
        'المقاسات: L', 'الألوان: بيج', 'المحافظة: الجيزة', '• العنوان: الهرم', 'آخر رسالة: «هو الشحن لأكتوبر بكام؟»',
    ])]);

    $h = $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->getJson("/inbox/conversations/{$c->id}/context")->json('data.handover');

    expect($h['reason'])->toBe('سؤال عن المقاس')->and($h['category'])->toBe('المقاسات')
        ->and($h['products'])->toBe(['اسدال كتان', 'عباية سادة'])->and($h['sizes'])->toBe(['L'])->and($h['colors'])->toBe(['بيج'])
        ->and($h['governorate'])->toBe('الجيزة')->and($h['last_message'])->toBe('هو الشحن لأكتوبر بكام؟')
        ->and($h['topic'])->toBe('سؤال عن المقاس')->and($h['lines'])->toBe([['label' => 'العنوان', 'value' => 'الهرم']]);
});

it('prefers the queue ticket summary for reason, order and lines', function () {
    $e = QueueEntry::factory()->create(['status' => 'active', 'bot_summary' => ['topic' => 'تأخير', 'category' => 'order_status', 'reason' => 'intent', 'order_number' => '1043', 'lines' => ['• رقم الأوردر: 1043']]]);
    Message::factory()->for($e->conversation)->create(['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer]);

    $data = $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->getJson("/inbox/conversations/{$e->conversation_id}/context")->json('data');

    expect($data['handover']['order_number'])->toBe('1043')->and($data['handover']['topic'])->toBe('تأخير')
        ->and($data['handover']['lines'])->toBe([['label' => 'رقم الأوردر', 'value' => '1043']])
        ->and($data['outcome']['auto'])->toBe('service');
});

it('serves the ad block with the latest referral first and a link only for ads roles', function () {
    $campaign = AdCampaign::factory()->create(['name' => 'خريف 2026']);
    $first = Ad::factory()->create(['name' => 'اسدال كتان', 'ad_campaign_id' => $campaign->id, 'thumbnail_url' => 'https://cdn.test/a.jpg']);
    $latest = Ad::factory()->create(['name' => 'عباية سادة', 'thumbnail_url' => 'https://cdn.test/b.jpg']);
    $c = s3CtxChat(['ad_id' => $first->external_id, 'ad_attributed_at' => now()->subDay(), 'ad_campaign_name' => 'خريف 2026']);
    DB::table('conversation_ad_referrals')->insert([
        ['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'ad_external_id' => $first->external_id, 'referred_at' => now()->subDay()],
        ['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'ad_external_id' => $latest->external_id, 'referred_at' => now()->subHour()],
    ]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);

    $ad = $this->actingAs($mod)->getJson("/inbox/conversations/{$c->id}/context")->assertOk()->json('data.ad');
    expect($ad['ad_id'])->toBe($latest->id)->and($ad['name'])->toBe('عباية سادة')->and($ad['thumbnail_url'])->toBe('https://cdn.test/b.jpg')
        ->and($ad['history'])->toHaveCount(2)->and($ad['can_open'])->toBeFalse();

    $admin = User::factory()->create(['role' => UserRole::Admin]);
    expect($this->actingAs($admin)->getJson("/inbox/conversations/{$c->id}/context")->json('data.ad.can_open'))->toBeTrue();
});

it('is web only and keeps the API thread payload unchanged', function () {
    $c = s3CtxChat();
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);
    Sanctum::actingAs($mod);

    $this->getJson("/api/v1/conversations/{$c->id}/context")->assertNotFound();
    expect(array_keys($this->getJson("/api/v1/conversations/{$c->id}")->assertOk()->json()))
        ->toBe(['conversation', 'messages', 'notes', 'customer', 'participants', 'cases', 'window', 'lock']);
});

it('refuses a moderator outside the platform', function () {
    $c = s3CtxChat();
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::WhatsApp]);

    $this->actingAs($mod)->getJson("/inbox/conversations/{$c->id}/context")->assertForbidden();
});

it('shares the first-reply target with the inbox page', function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/inbox')->assertOk()
        ->assertInertia(fn ($page) => $page->where('firstReplyTargetSeconds', fn ($v) => is_int($v) && $v > 0));
});
