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
use App\Models\QueueEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-08 12:00', 'Africa/Cairo')));

function s3EndIn(Conversation $c, string $body = 'عايزة اسأل'): Message
{
    return Message::factory()->for($c)->create(['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => $body]);
}

it('stores the picked outcome with its author, its note only for other, and the watermark', function () {
    $c = Conversation::factory()->create();
    $first = s3EndIn($c);
    $last = Message::factory()->for($c)->create();
    $u = User::factory()->create();
    $r = app(OutcomeRecorder::class);

    $row = $r->endEpisode($c, null, Outcome::Other, 'عايزة لون مش عندنا', $u, EpisodeEnd::Resolve);

    expect($row->episode_key)->toBe('m'.$first->id)
        ->and($row->outcome)->toBe('other')->and($row->note)->toBe('عايزة لون مش عندنا')
        ->and($row->source)->toBe('agent')->and($row->set_by_id)->toBe($u->id)
        ->and($row->last_message_id)->toBe($last->id)->and($row->ended_by)->toBe('resolve')
        ->and($row->started_at)->not->toBeNull()->and($row->ended_at)->not->toBeNull();

    $price = $r->endEpisode($c, null, Outcome::Price, 'ignored', $u, EpisodeEnd::Resolve);
    expect($price->id)->toBe($row->id); // nothing new since the end: the same row, unchanged
    expect($price->outcome)->toBe('other');
});

it('keeps ordered over the picked outcome and the picked one over service', function () {
    $c = Conversation::factory()->create(['handover_category' => 'return', 'handover_at' => now()]);
    s3EndIn($c);
    $u = User::factory()->create();
    $r = app(OutcomeRecorder::class);

    expect($r->endEpisode($c, null, Outcome::Price, null, $u, EpisodeEnd::Resolve)->outcome)->toBe('price');

    Carbon::setTestNow(now()->addMinute());
    s3EndIn($c);
    $c->forceFill(['handover_at' => now()])->save();
    $service = $r->endEpisode($c->fresh(), null, null, null, $u, EpisodeEnd::Resolve);
    expect($service->outcome)->toBe('service')->and($service->source)->toBe('auto')->and($service->set_by_id)->toBeNull();

    Carbon::setTestNow(now()->addMinute());
    s3EndIn($c);
    $order = Order::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'status' => 'confirmed']);
    $ordered = $r->endEpisode($c->fresh(), null, Outcome::Price, null, $u, EpisodeEnd::Resolve);
    expect($ordered->outcome)->toBe('ordered')->and($ordered->source)->toBe('auto')->and($ordered->order_id)->toBe($order->id);
});

it('records no_answer on a silence auto-close and unknown on an API resolve with nothing', function () {
    $r = app(OutcomeRecorder::class);
    $e = QueueEntry::factory()->create(['status' => 'active', 'delivered_at' => now(), 'bot_summary' => ['category' => 'sizes']]);
    s3EndIn($e->conversation);
    $auto = $r->endEpisode($e->conversation, $e, null, null, null, EpisodeEnd::AutoClose);
    expect($auto->outcome)->toBe('no_answer')->and($auto->queue_entry_id)->toBe($e->id)->and($auto->reached_agent)->toBeTrue();

    $c = Conversation::factory()->create();
    s3EndIn($c);
    $api = $r->endEpisode($c, null, null, null, User::factory()->create(), EpisodeEnd::ApiResolve);
    expect($api->outcome)->toBe('unknown')->and($api->source)->toBe('agent')->and($api->reached_agent)->toBeFalse();
});

it('closes the open ordered row instead of adding another', function () {
    $c = Conversation::factory()->create();
    s3EndIn($c);
    $order = Order::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'status' => 'submitting']);
    $r = app(OutcomeRecorder::class);
    $open = $r->orderPlaced($order);

    $closed = $r->endEpisode($c, null, Outcome::Browsing, null, User::factory()->create(), EpisodeEnd::Resolve);

    expect($closed->id)->toBe($open->id)->and($closed->outcome)->toBe('ordered')->and($closed->ended_at)->not->toBeNull()
        ->and(ConversationOutcome::count())->toBe(1);
});

it('ends an idle bot chat as no_answer, and one nobody answered as unknown', function () {
    $r = app(OutcomeRecorder::class);
    $bot = Conversation::factory()->create(['handler' => 'bot']);
    s3EndIn($bot);
    Message::factory()->for($bot)->create(['sender_type' => SenderType::Bot]);
    expect($r->endIdle($bot)->outcome)->toBe('no_answer');

    $dropped = Conversation::factory()->create(['handler' => 'human']);
    Message::factory()->for($dropped)->create(['sender_type' => SenderType::Bot]);
    s3EndIn($dropped);
    $row = $r->endIdle($dropped);
    expect($row->outcome)->toBe('unknown')->and($row->ended_by)->toBe('idle');
});

it('does not end an idle episode made only of a thanks or a rating answer, nor one before tracking started', function () {
    $r = app(OutcomeRecorder::class);
    $c = Conversation::factory()->create();
    s3EndIn($c, 'شكرا');
    s3EndIn($c, '5');
    expect($r->endIdle($c))->toBeNull()->and(ConversationOutcome::count())->toBe(0);

    config(['crm.outcomes.tracking_from' => '2026-10-09']);
    $later = Conversation::factory()->create();
    s3EndIn($later, 'المقاس L موجود؟');
    expect($r->endIdle($later))->toBeNull();
});
