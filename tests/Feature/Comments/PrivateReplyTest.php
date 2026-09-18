<?php

use App\Analytics\ActivityLogger;
use App\Channels\Adapters\FakeChannelAdapter;
use App\Channels\ChannelRegistry;
use App\Channels\Contracts\ChannelAdapter;
use App\Channels\Data\{ChannelCapabilities, SendResult};
use App\Comments\{CommentActionFailedException, CommentActions, PrivateReplyNotAllowedException};
use App\Enums\{Platform, ConversationSource};
use App\Inbox\InboxIngestor;
use App\Models\{ActivityLog, ChannelAccount, Comment, Message, Post, Customer, CustomerIdentity};
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    Event::fake();
    $acc = ChannelAccount::factory()->create(['platform'=>Platform::Facebook]);
    $cust = Customer::factory()->create();
    CustomerIdentity::factory()->for($cust)->create(['platform'=>Platform::Facebook,'external_id'=>'U1']);
    $post = Post::factory()->for($acc,'channelAccount')->create(['platform'=>Platform::Facebook,'is_ad'=>true]);
    $this->comment = Comment::factory()->for($post)->for($cust)->create(['external_id'=>'C1','created_at'=>now()->subDay()]);
});

it('sends one private reply and opens an ad-sourced conversation', function () {
    $conv = app(CommentActions::class)->privateReply($this->comment, 'بعتنالك التفاصيل', null);
    expect($conv->source)->toBe(ConversationSource::Ad)->and($conv->source_comment_id)->toBe($this->comment->id)
        ->and($this->comment->fresh()->private_reply_sent_at)->not->toBeNull();
    app(CommentActions::class)->privateReply($this->comment->fresh(), 'تاني', null);
})->throws(PrivateReplyNotAllowedException::class);

it('refuses private reply after 7 days', function () {
    // created_at is not mass-assignable on Comment (see $fillable), so age it
    // directly rather than via update().
    $this->comment->forceFill(['created_at' => now()->subDays(8)])->save();
    app(CommentActions::class)->privateReply($this->comment->fresh(), 'x', null);
})->throws(PrivateReplyNotAllowedException::class);

it('refuses private reply on tiktok', function () {
    $acc = ChannelAccount::factory()->create(['platform'=>Platform::TikTok]);
    $post = Post::factory()->for($acc,'channelAccount')->create(['platform'=>Platform::TikTok]);
    $c = Comment::factory()->for($post)->create();
    app(CommentActions::class)->privateReply($c, 'x', null);
})->throws(PrivateReplyNotAllowedException::class);

it('refuses a private reply claimed by another process without calling the adapter', function () {
    // Simulate a race: the DB row is already claimed, but our in-memory
    // model instance is stale and still shows private_reply_sent_at as null.
    Comment::whereKey($this->comment->id)->update(['private_reply_sent_at' => now()]);

    expect(fn () => app(CommentActions::class)->privateReply($this->comment, 'x', null))
        ->toThrow(PrivateReplyNotAllowedException::class);

    expect(FakeChannelAdapter::sent())->toBeEmpty();
});

it('rolls back the claim when the adapter throws instead of failing', function () {
    $throwingAdapter = new class(Platform::Facebook) implements ChannelAdapter
    {
        public function __construct(private readonly Platform $platform) {}

        public function platform(): Platform
        {
            return $this->platform;
        }

        public function capabilities(): ChannelCapabilities
        {
            return new ChannelCapabilities(privateReply: true, hideComment: true, windowHours: 24, humanAgentHours: 168, templatesOutsideWindow: false);
        }

        public function handshake(Request $request): ?Response
        {
            return null;
        }

        public function verifySignature(Request $request): bool
        {
            return true;
        }

        public function normalize(array $payload): array
        {
            return [];
        }

        public function sendText(\App\Models\ChannelAccount $account, \App\Models\CustomerIdentity $to, string $text, array $options = []): SendResult
        {
            throw new RuntimeException('not used in this test');
        }

        public function sendAttachment(\App\Models\ChannelAccount $account, \App\Models\CustomerIdentity $to, \App\Models\MessageAttachment $attachment, ?string $caption = null, array $options = []): SendResult
        {
            throw new RuntimeException('not used in this test');
        }

        public function replyToComment(\App\Models\ChannelAccount $account, string $commentExternalId, string $text): SendResult
        {
            throw new RuntimeException('not used in this test');
        }

        public function hideComment(\App\Models\ChannelAccount $account, string $commentExternalId): SendResult
        {
            throw new RuntimeException('not used in this test');
        }

        public function sendPrivateReply(\App\Models\ChannelAccount $account, string $commentExternalId, string $text): SendResult
        {
            throw new ConnectionException('Connection timed out');
        }

        public function typing(\App\Models\ChannelAccount $account, \App\Models\CustomerIdentity $to, bool $on): void
        {
            // not used in this test
        }
    };

    $stubRegistry = new class($throwingAdapter) extends ChannelRegistry
    {
        public function __construct(private readonly ChannelAdapter $stub) {}

        public function adapter(Platform $platform): ChannelAdapter
        {
            return $this->stub;
        }
    };

    app()->instance(ChannelRegistry::class, $stubRegistry);

    expect(fn () => app(CommentActions::class)->privateReply($this->comment, 'x', null))
        ->toThrow(CommentActionFailedException::class);

    expect(Comment::find($this->comment->id)->private_reply_sent_at)->toBeNull();
});

it('keeps the claim and records a persist-failure activity log when saving the message fails', function () {
    app()->instance(InboxIngestor::class, new class extends InboxIngestor
    {
        public function __construct() {}

        public function openConversationFor(\App\Models\CustomerIdentity $i, \App\Models\ChannelAccount $a, \App\Enums\ConversationSource $src = \App\Enums\ConversationSource::Direct, ?int $sourceCommentId = null): \App\Models\Conversation
        {
            throw new RuntimeException('db down while opening conversation');
        }
    });

    expect(fn () => app(CommentActions::class)->privateReply($this->comment, 'x', null))
        ->toThrow(RuntimeException::class, 'db down while opening conversation');

    // The external private reply was already sent, so the claim must stand:
    // a retry must not send a second one.
    expect(Comment::find($this->comment->id)->private_reply_sent_at)->not->toBeNull();
    expect(Message::count())->toBe(0);

    $log = ActivityLog::where('action', ActivityLogger::COMMENT_PRIVATE_REPLY)
        ->where('subject_id', $this->comment->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->meta['persist_failed'] ?? null)->toBeTrue()
        ->and($log->meta['error'] ?? null)->toBe('db down while opening conversation');
});

it('records the post platform on the comment.replied activity log', function () {
    app(CommentActions::class)->reply($this->comment, 'ردينا عليك', null);

    $log = ActivityLog::where('action', ActivityLogger::COMMENT_REPLIED)
        ->where('subject_id', $this->comment->id)
        ->first();

    expect($log)->not->toBeNull()->and($log->platform)->toBe(Platform::Facebook);
});
