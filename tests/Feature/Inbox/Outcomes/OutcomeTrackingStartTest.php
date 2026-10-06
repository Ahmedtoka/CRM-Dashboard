<?php

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Inbox\Outcomes\EpisodeEnd;
use App\Inbox\Outcomes\Outcome;
use App\Inbox\Outcomes\OutcomeRecorder;
use App\Models\Conversation;
use App\Models\ConversationOutcome;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;

// Review round 1 (IMPORTANT 2 + minor): history before tracking_from never leaks into the first episode,
// and a thanks / rating answer never starts an episode.

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:00', 'Africa/Cairo'));
    config(['crm.outcomes.tracking_from' => '2026-10-08']);
});

function s3TrackIn(Conversation $c, string $body, Carbon $at): Message
{
    return Message::factory()->for($c)->create(['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => $body, 'created_at' => $at]);
}

it('ignores orders, handovers and messages from before tracking started', function () {
    $old = now()->subDays(20);
    $c = Conversation::factory()->create(['handover_category' => 'return', 'handover_at' => $old]);
    s3TrackIn($c, 'قديم', $old);
    Order::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'status' => 'confirmed', 'created_at' => $old]);
    $new = s3TrackIn($c, 'المقاس L موجود؟', now()->subHour());
    $r = app(OutcomeRecorder::class);

    expect($r->episode($c)['key'])->toBe('m'.$new->id)
        ->and($r->autoOutcome($c))->toBeNull();
});

it('lets the idle sweep end the first post-deploy episode of an old conversation', function () {
    $c = Conversation::factory()->create(['handler' => 'bot']);
    s3TrackIn($c, 'قديم', now()->subDays(20));
    s3TrackIn($c, 'بكام ده؟', now()->subDays(1));
    Message::factory()->for($c)->create(['sender_type' => SenderType::Bot]);

    $row = app(OutcomeRecorder::class)->endIdle($c);

    expect($row)->not->toBeNull()->and($row->outcome)->toBe('no_answer')->and($row->ended_by)->toBe('idle');
});

it('does not start a new episode on a thanks or a rating answer after a close', function () {
    $c = Conversation::factory()->create();
    s3TrackIn($c, 'عايزة اسأل', now()->subHours(2));
    $r = app(OutcomeRecorder::class);
    $u = User::factory()->create();
    $ended = $r->endEpisode($c, null, Outcome::Price, null, $u, EpisodeEnd::Resolve);

    Carbon::setTestNow(now()->addMinutes(5));
    s3TrackIn($c, 'شكرا', now());
    s3TrackIn($c, '5', now());

    expect($r->episode($c)['first_message_id'])->toBeNull()
        ->and($r->endEpisode($c, null, null, null, $u, EpisodeEnd::Resolve)->id)->toBe($ended->id)
        ->and(ConversationOutcome::count())->toBe(1);

    $next = s3TrackIn($c, 'عندكم لون كحلي؟', now());
    expect($r->episode($c)['key'])->toBe('m'.$next->id);
});
