<?php

use App\Analytics\ActivityLogger;
use App\Bot\Ai\{AiReply, AiResponder, Classification};
use App\Comments\CommentBot;
use App\Enums\{ActorType, Handler, Platform, CommentStatus, CommentIntent, UserRole};
use App\Events\UserNotified;
use App\Models\{ActivityLog, BotRule, BotSetting, ChannelAccount, Comment, Conversation, Post, Customer, CustomerIdentity, User};
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
    BotSetting::current()->update(['enabled'=>true,'ai_enabled'=>true]);
    $acc = ChannelAccount::factory()->create(['platform'=>Platform::Instagram]);
    $this->cust = Customer::factory()->create();
    CustomerIdentity::factory()->for($this->cust)->create(['platform'=>Platform::Instagram]);
    $this->post = Post::factory()->for($acc,'channelAccount')->create(['platform'=>Platform::Instagram]);
});

it('replies publicly and privately on rule match', function () {
    BotRule::factory()->create(['scope'=>'comment','keywords'=>['بكام'],'public_replies'=>['ردينا عليكي في الخاص 💌'],'private_reply'=>'السعر 1250 جنيه','action'=>'reply','platforms'=>[],'is_active'=>true]);
    $c = Comment::factory()->for($this->post)->for($this->cust)->create(['body'=>'بكام؟']);
    app(CommentBot::class)->handle($c);
    $c->refresh();
    expect($c->status)->toBe(CommentStatus::Replied)->and($c->replied_by_type)->toBe(ActorType::Bot)
        ->and($c->private_reply_sent_at)->not->toBeNull()->and($c->conversation_id)->not->toBeNull();
});

it('hides spam via ai classification', function () {
    $c = Comment::factory()->for($this->post)->for($this->cust)->create(['body'=>'اربح 5000 دولار من البيت http://x.y']);
    app(CommentBot::class)->handle($c);
    expect($c->fresh()->status)->toBe(CommentStatus::Hidden)->and($c->fresh()->intent)->toBe(CommentIntent::Spam);
});

it('never leaks the rule private_reply text publicly when no public_replies are set', function () {
    BotRule::factory()->create(['scope'=>'comment','keywords'=>['السعر'],'public_replies'=>[],'private_reply'=>'السعر السري 1250 جنيه بس متقولش لحد','action'=>'reply','platforms'=>[],'is_active'=>true]);
    $c = Comment::factory()->for($this->post)->for($this->cust)->create(['body'=>'السعر كام؟']);
    app(CommentBot::class)->handle($c);
    $c->refresh();
    expect($c->public_reply)->not->toBeNull()
        ->and($c->public_reply)->not->toBe('السعر السري 1250 جنيه بس متقولش لحد')
        ->and($c->public_reply)->toBe('ردينا عليك في الخاص 💌');
});

it('does not promise a private reply on a platform without the capability', function () {
    $acc = ChannelAccount::factory()->create(['platform' => Platform::TikTok]);
    $cust = Customer::factory()->create();
    CustomerIdentity::factory()->for($cust)->create(['platform' => Platform::TikTok]);
    $post = Post::factory()->for($acc, 'channelAccount')->create(['platform' => Platform::TikTok]);
    $c = Comment::factory()->for($post)->for($cust)->create(['body' => 'بكام السعر']);

    app(CommentBot::class)->handle($c);
    $c->refresh();

    expect($c->public_reply)->not->toBe('ردينا عليك في الخاص 💌')
        ->and($c->public_reply)->toBe('ابعتلنا على الخاص أو الواتساب للتفاصيل 💬')
        ->and($c->conversation_id)->toBeNull();
});

it('falls back to the safe private reply and flags humans when the ai text fails the price guard', function () {
    $supervisor = User::factory()->create(['role' => UserRole::Supervisor, 'is_active' => true]);

    app()->instance(AiResponder::class, new class implements AiResponder
    {
        public function classify(string $text): Classification
        {
            return new Classification(CommentIntent::Question, 0.9, false);
        }

        public function reply(array $history, array $catalogLines, string $systemPrompt): AiReply
        {
            // Invents a price that is not present in the (empty) catalog.
            return new AiReply('reply', 'السعر بقى 500 جنيه', 'test-model');
        }
    });

    $c = Comment::factory()->for($this->post)->for($this->cust)->create(['body' => 'بكام السعر']);

    app(CommentBot::class)->handle($c);
    $c->refresh();

    expect($c->conversation_id)->not->toBeNull();
    $message = $c->conversation->messages()->latest('id')->first();
    expect($message->body)->toBe('أهلاً! ابعتلنا تحب تعرف إيه عن المنتج');

    Event::assertDispatched(UserNotified::class, fn (UserNotified $e) => $e->userId === $supervisor->id && $e->type === 'comment.ai_guard');

    $log = ActivityLog::where('action', ActivityLogger::COMMENT_FLAGGED)->where('subject_id', $c->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->actor_type)->toBe(ActorType::Bot)
        ->and($log->meta)->toBe(['reason' => 'ai_guard', 'intent' => 'question']);
});

it('logs a comment.flagged activity entry for a complaint', function () {
    User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

    $c = Comment::factory()->for($this->post)->for($this->cust)->create(['body' => 'في مشكله في الشحن ده نصب']);

    app(CommentBot::class)->handle($c);

    expect($c->fresh()->intent)->toBe(CommentIntent::Complaint)
        ->and($c->fresh()->status)->toBe(CommentStatus::New);

    Event::assertDispatched(UserNotified::class, fn (UserNotified $e) => $e->type === 'comment.complaint');

    $log = ActivityLog::where('action', ActivityLogger::COMMENT_FLAGGED)->where('subject_id', $c->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->actor_type)->toBe(ActorType::Bot)
        ->and($log->meta)->toBe(['reason' => 'complaint', 'intent' => 'complaint']);
});

it('hands the conversation to a human after a reply_and_handover rule', function () {
    BotRule::factory()->create(['scope'=>'comment','keywords'=>['الغاء'],'public_replies'=>['هنتواصل معاك في الخاص'],'private_reply'=>'تفاصيل الالغاء','action'=>'reply_and_handover','platforms'=>[],'is_active'=>true]);
    $c = Comment::factory()->for($this->post)->for($this->cust)->create(['body'=>'عايز الغاء الاوردر']);

    app(CommentBot::class)->handle($c);
    $c->refresh();

    expect($c->conversation_id)->not->toBeNull();
    $conversation = Conversation::find($c->conversation_id);
    expect($conversation->handler)->toBe(Handler::Human)->and($conversation->needs_human)->toBeTrue();
});
