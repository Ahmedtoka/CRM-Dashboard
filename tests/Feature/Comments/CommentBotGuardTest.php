<?php

use App\Channels\Adapters\FakeChannelAdapter;
use App\Comments\CommentBot;
use App\Comments\Jobs\RunCommentBot;
use App\Enums\{ActorType, CommentStatus, Platform, UserRole};
use App\Models\{BotRule, BotRun, BotSetting, ChannelAccount, Comment, Customer, CustomerIdentity, Post, User};
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
    FakeChannelAdapter::reset();
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true]);
    BotRule::factory()->create(['scope' => 'comment', 'keywords' => ['بكام'], 'public_replies' => ['ردينا عليكي في الخاص 💌'], 'private_reply' => 'السعر 1250 جنيه', 'action' => 'reply', 'platforms' => [], 'is_active' => true]);

    $acc = ChannelAccount::factory()->create(['platform' => Platform::Instagram]);
    $this->cust = Customer::factory()->create();
    CustomerIdentity::factory()->for($this->cust)->create(['platform' => Platform::Instagram]);
    $this->post = Post::factory()->for($acc, 'channelAccount')->create(['platform' => Platform::Instagram]);
});

function sentMethods(): array
{
    return array_column(FakeChannelAdapter::sent(), 'method');
}

it('does nothing when a human already replied during the bot delay', function () {
    $mod = User::factory()->create(['role' => UserRole::Supervisor]);
    $c = Comment::factory()->for($this->post)->for($this->cust)->create([
        'body' => 'بكام؟',
        'status' => CommentStatus::Replied,
        'public_reply' => 'أهلا، ردينا',
        'public_replied_at' => now(),
        'replied_by_type' => ActorType::User,
        'replied_by_id' => $mod->id,
    ]);

    (new RunCommentBot($c->id))->handle(app(CommentBot::class));

    expect(BotRun::count())->toBe(0)
        ->and(sentMethods())->toBe([])
        ->and($c->fresh()->public_reply)->toBe('أهلا، ردينا');
});

it('does nothing for a hidden comment', function () {
    $c = Comment::factory()->for($this->post)->for($this->cust)->create(['body' => 'بكام؟', 'status' => CommentStatus::Hidden]);

    expect(app(CommentBot::class)->handle($c))->toBeNull()
        ->and(sentMethods())->toBe([]);
});

it('does not post a second public reply when a partially successful run is retried', function () {
    $c = Comment::factory()->for($this->post)->for($this->cust)->create(['body' => 'بكام؟']);

    // First run: public reply succeeds, private reply fails at the platform.
    FakeChannelAdapter::reset();
    $bot = app(CommentBot::class);
    try {
        // failNext applies to the next adapter call: queue it right after the public reply.
        $c->post->channelAccount; // warm relation
        $actions = app(App\Comments\CommentActions::class);
        $actions->reply($c, 'ردينا عليكي في الخاص 💌', null);
        FakeChannelAdapter::failNext('(#10) temporary failure');
        $actions->privateReply($c->fresh(), 'السعر 1250 جنيه', null);
    } catch (App\Comments\CommentActionFailedException) {
        // expected
    }

    $before = count(array_keys(sentMethods(), 'replyToComment'));

    (new RunCommentBot($c->id))->handle($bot);

    expect(count(array_keys(sentMethods(), 'replyToComment')))->toBe($before)
        ->and($before)->toBe(1);
});

it('skips the private reply when one was already sent before the bot got there', function () {
    $c = Comment::factory()->for($this->post)->for($this->cust)->create(['body' => 'بكام؟', 'private_reply_sent_at' => now()]);

    $run = app(CommentBot::class)->handle($c);

    expect($run)->not->toBeNull()
        ->and(sentMethods())->toBe(['replyToComment'])
        ->and($c->fresh()->status)->toBe(CommentStatus::Replied);
});

it('runs the comment bot job only once', function () {
    expect((new RunCommentBot(1))->tries)->toBe(1);
});
