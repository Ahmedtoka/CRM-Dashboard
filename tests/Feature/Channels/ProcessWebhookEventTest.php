<?php

use App\Channels\Data\DeliveryReceiptData;
use App\Channels\Data\InboundCommentData;
use App\Channels\Data\InboundMessageData;
use App\Channels\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;

function fakeWebhookPayload(): array
{
    return [
        'fake' => true,
        'events' => [
            ['type' => 'message', 'customer_id' => 'c1', 'name' => 'Ahmed', 'text' => 'بكام', 'id' => 'm1', 'at' => '2026-09-12T10:00:00Z', 'phone' => null],
            ['type' => 'comment', 'post_id' => 'p1', 'post_caption' => 'caption', 'is_ad' => false, 'comment_id' => 'cm1', 'customer_id' => 'c1', 'name' => 'Ahmed', 'text' => 'بكام', 'at' => '2026-09-12T10:00:00Z'],
            ['type' => 'receipt', 'message_id' => 'fake_x', 'status' => 'delivered', 'at' => '2026-09-12T10:00:00Z'],
        ],
    ];
}

it('routes each normalized dto to the right ingestor and marks the event processed', function () {
    $event = WebhookEvent::factory()->create([
        'provider' => 'whatsapp',
        'payload' => fakeWebhookPayload(),
        'status' => 'received',
        'attempts' => 0,
    ]);

    $inbox = new class
    {
        public array $messages = [];

        public array $receipts = [];

        public function ingestMessage($dto)
        {
            $this->messages[] = $dto;

            return null;
        }

        public function ingestReceipt($dto): void
        {
            $this->receipts[] = $dto;
        }
    };

    $comments = new class
    {
        public array $comments = [];

        public function ingest($dto)
        {
            $this->comments[] = $dto;

            return null;
        }
    };

    // Bind concrete instances under the exact string keys the job resolves
    // at runtime. app()->instance() overrides any prior/real binding, which
    // matters because Task 3 may register a real App\Inbox\InboxIngestor
    // concurrently in this same working tree.
    app()->instance('App\Inbox\InboxIngestor', $inbox);
    app()->instance('App\Comments\CommentIngestor', $comments);

    app()->call([new ProcessWebhookEvent($event->id), 'handle']);

    expect($inbox->messages)->toHaveCount(1)
        ->and($inbox->messages[0])->toBeInstanceOf(InboundMessageData::class)
        ->and($inbox->messages[0]->body)->toBe('بكام')
        ->and($inbox->receipts)->toHaveCount(1)
        ->and($inbox->receipts[0])->toBeInstanceOf(DeliveryReceiptData::class)
        ->and($inbox->receipts[0]->externalMessageId)->toBe('fake_x')
        ->and($comments->comments)->toHaveCount(1)
        ->and($comments->comments[0])->toBeInstanceOf(InboundCommentData::class)
        ->and($comments->comments[0]->commentExternalId)->toBe('cm1');

    $event->refresh();

    expect($event->status)->toBe('processed')
        ->and($event->processed_at)->not->toBeNull()
        ->and($event->attempts)->toBe(1);
});

it('marks the event failed, records the error and rethrows when an ingestor throws', function () {
    $event = WebhookEvent::factory()->create([
        'provider' => 'whatsapp',
        'payload' => fakeWebhookPayload(),
        'status' => 'received',
        'attempts' => 0,
    ]);

    app()->instance('App\Inbox\InboxIngestor', new class
    {
        public function ingestMessage($dto)
        {
            throw new RuntimeException('ingest boom');
        }

        public function ingestReceipt($dto): void {}
    });

    app()->instance('App\Comments\CommentIngestor', new class
    {
        public function ingest($dto)
        {
            return null;
        }
    });

    expect(fn () => app()->call([new ProcessWebhookEvent($event->id), 'handle']))
        ->toThrow(RuntimeException::class, 'ingest boom');

    $event->refresh();

    expect($event->status)->toBe('failed')
        ->and($event->error)->toBe('ingest boom')
        ->and($event->attempts)->toBe(1)
        ->and($event->processed_at)->toBeNull();
});

it('stores a long failure message (once beyond varchar(255)) without the update itself throwing, and rethrows the original', function () {
    $event = WebhookEvent::factory()->create([
        'provider' => 'whatsapp',
        'payload' => fakeWebhookPayload(),
        'status' => 'received',
        'attempts' => 0,
    ]);

    $longMessage = str_repeat('x', 1000);

    app()->instance('App\Inbox\InboxIngestor', new class($longMessage)
    {
        public function __construct(private readonly string $message) {}

        public function ingestMessage($dto)
        {
            throw new RuntimeException($this->message);
        }

        public function ingestReceipt($dto): void {}
    });

    app()->instance('App\Comments\CommentIngestor', new class
    {
        public function ingest($dto)
        {
            return null;
        }
    });

    expect(fn () => app()->call([new ProcessWebhookEvent($event->id), 'handle']))
        ->toThrow(RuntimeException::class, $longMessage);

    $event->refresh();

    expect($event->status)->toBe('failed')
        ->and($event->error)->toBe($longMessage)
        ->and(strlen($event->error))->toBe(1000)
        ->and($event->attempts)->toBe(1)
        ->and($event->processed_at)->toBeNull();
});

it('truncates a failure message longer than the 2000-char cap instead of storing it in full', function () {
    $event = WebhookEvent::factory()->create([
        'provider' => 'whatsapp',
        'payload' => fakeWebhookPayload(),
        'status' => 'received',
        'attempts' => 0,
    ]);

    $hugeMessage = str_repeat('y', 5000);

    app()->instance('App\Inbox\InboxIngestor', new class($hugeMessage)
    {
        public function __construct(private readonly string $message) {}

        public function ingestMessage($dto)
        {
            throw new RuntimeException($this->message);
        }

        public function ingestReceipt($dto): void {}
    });

    app()->instance('App\Comments\CommentIngestor', new class
    {
        public function ingest($dto)
        {
            return null;
        }
    });

    expect(fn () => app()->call([new ProcessWebhookEvent($event->id), 'handle']))
        ->toThrow(RuntimeException::class);

    $event->refresh();

    expect($event->status)->toBe('failed')
        ->and(strlen($event->error))->toBeLessThan(2010)
        ->and($event->error)->toStartWith(str_repeat('y', 50));
});
