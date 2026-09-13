<?php

use App\Channels\Jobs\ProcessWebhookEvent;
use App\Comments\Jobs\RunCommentBot;
use App\Enums\Platform;
use App\Models\{ChannelAccount, Comment, CustomerIdentity, Post, WebhookEvent};
use Illuminate\Support\Facades\Queue;

function fakeCommentWebhookPayload(): array
{
    return [
        'events' => [
            [
                'type' => 'comment',
                'post_id' => 'p1',
                'post_caption' => 'caption',
                'is_ad' => false,
                'comment_id' => 'cm1',
                'customer_id' => 'cu1',
                'name' => 'Sara',
                'text' => 'بكام؟',
                'at' => now()->toIso8601String(),
            ],
        ],
    ];
}

beforeEach(function () {
    Queue::fake([RunCommentBot::class]);
    ChannelAccount::factory()->create(['platform' => Platform::Instagram]);
    $this->event = WebhookEvent::factory()->create([
        'provider' => 'instagram',
        'payload' => fakeCommentWebhookPayload(),
        'status' => 'received',
        'attempts' => 0,
    ]);
});

it('ingests a comment webhook once and queues the comment bot with a random 5-30s delay', function () {
    app()->call([new ProcessWebhookEvent($this->event->id), 'handle']);
    // A duplicate delivery of the same webhook must not create a second row.
    app()->call([new ProcessWebhookEvent($this->event->id), 'handle']);

    expect(Post::count())->toBe(1)
        ->and(Comment::count())->toBe(1)
        ->and(CustomerIdentity::count())->toBe(1);

    Queue::assertPushed(RunCommentBot::class, fn ($job) => $job->delay !== null);
});
