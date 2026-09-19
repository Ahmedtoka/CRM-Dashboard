<?php

use App\Analytics\ActivityLogger;
use App\Enums\{ConversationStatus, Platform, UserRole};
use App\Models\{ActivityLog, BotRun, ChannelAccount, Conversation, ConversationNote, Message, SupportCase, User};
use Illuminate\Support\Facades\Event;

it('resets a conversation to first contact for a supervisor', function () {
    Event::fake();
    $c = Conversation::factory()->for(ChannelAccount::factory()->state(['platform'=>Platform::Facebook]), 'channelAccount')->create([
        'platform'=>Platform::Facebook, 'status'=>ConversationStatus::Resolved, 'handler'=>'human', 'needs_human'=>true,
        'unread_count'=>4, 'bot_state'=>['last_turn_message_id'=>9, 'delayed_response_sent'=>true],
        'priority_level'=>'high', 'queue'=>'senior', 'handover_category'=>'complaint', 'last_message_at'=>now(), 'handover_at'=>now(),
    ]);
    Message::factory()->count(3)->create(['conversation_id'=>$c->id]);
    ConversationNote::factory()->create(['conversation_id'=>$c->id]);
    BotRun::factory()->create(['conversation_id'=>$c->id]);
    SupportCase::factory()->create(['conversation_id'=>$c->id]);
    $other = SupportCase::factory()->create();
    $sup = User::factory()->create(['role'=>UserRole::Supervisor]);

    $this->actingAs($sup)->postJson("/inbox/conversations/{$c->id}/reset")
        ->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.can.reset', true);

    $fresh = $c->fresh();
    expect(Message::where('conversation_id', $c->id)->count())->toBe(0)
        ->and(ConversationNote::where('conversation_id', $c->id)->count())->toBe(0)
        ->and(BotRun::where('conversation_id', $c->id)->count())->toBe(0)
        ->and(SupportCase::where('conversation_id', $c->id)->count())->toBe(0)
        ->and(SupportCase::whereKey($other->id)->exists())->toBeTrue()
        ->and($fresh->status)->toBe(ConversationStatus::Open)
        ->and($fresh->handler->value)->toBe('bot')
        ->and($fresh->needs_human)->toBeFalse()
        ->and($fresh->bot_state)->toBeNull()
        ->and($fresh->priority_level)->toBeNull()
        ->and($fresh->queue)->toBeNull()
        ->and($fresh->unread_count)->toBe(0)
        ->and($fresh->last_message_at)->toBeNull()
        ->and(ActivityLog::where('action', ActivityLogger::CONVERSATION_RESET)->where('user_id', $sup->id)->where('conversation_id', $c->id)->exists())->toBeTrue();
});

it('forbids a moderator from resetting a conversation', function () {
    $c = Conversation::factory()->for(ChannelAccount::factory()->state(['platform'=>Platform::Facebook]), 'channelAccount')->create(['platform'=>Platform::Facebook]);
    Message::factory()->create(['conversation_id'=>$c->id]);
    $mod = User::factory()->create(['role'=>UserRole::Moderator]); $mod->userPlatforms()->create(['platform'=>Platform::Facebook]);

    $this->actingAs($mod)->postJson("/inbox/conversations/{$c->id}/reset")->assertForbidden();
    $this->actingAs($mod)->getJson("/inbox/conversations/{$c->id}")->assertOk()->assertJsonPath('conversation.can.reset', false);

    expect(Message::where('conversation_id', $c->id)->count())->toBe(1);
});
