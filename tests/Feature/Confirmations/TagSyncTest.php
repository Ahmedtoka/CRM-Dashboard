<?php

use App\Confirmations\Contracts\ShopifyOrderTagger;
use App\Confirmations\TagSync;
use App\Enums\OrderConfirmationStatus;
use App\Models\Order;
use App\Models\OrderConfirmation;

/** A tagger that answers $ok and records every call. */
function recordingTagger(bool $ok = true): object
{
    $tagger = new class($ok) implements ShopifyOrderTagger
    {
        /** @var list<array{order: int, tags: list<string>}> */
        public array $calls = [];

        public function __construct(public bool $ok) {}

        public function add(Order $order, array $tags): bool
        {
            $this->calls[] = ['order' => $order->id, 'tags' => $tags];

            return $this->ok;
        }
    };

    app()->instance(ShopifyOrderTagger::class, $tagger);

    return $tagger;
}

it('pushes the tag of the current status and marks it synced', function () {
    $tagger = recordingTagger();
    $c = OrderConfirmation::factory()->status(OrderConfirmationStatus::Confirmed)->create();

    expect(app(TagSync::class)->push($c))->toBeTrue();

    $c->refresh();
    expect($tagger->calls)->toBe([['order' => $c->order_id, 'tags' => ['wa-confirmed']]])
        ->and($c->tag_synced_at)->not->toBeNull()
        ->and($c->pending_tags)->toBeNull();
});

it('does nothing for a status without a tag', function () {
    $tagger = recordingTagger();
    $c = OrderConfirmation::factory()->sent()->create();

    expect(app(TagSync::class)->push($c))->toBeTrue()
        ->and($tagger->calls)->toBe([])
        ->and($c->fresh()->tag_synced_at)->toBeNull();
});

it('keeps a failed tag pending and the next push sends it again with the new one', function () {
    $tagger = recordingTagger(false);
    $c = OrderConfirmation::factory()->status(OrderConfirmationStatus::NoResponse)->create(['tag_synced_at' => now()->subDay()]);

    expect(app(TagSync::class)->push($c))->toBeFalse();

    $c->refresh();
    expect($c->tag_synced_at)->toBeNull()
        ->and($c->pending_tags)->toBe(['tags' => ['wa-no-response'], 'attempts' => 1]);

    $tagger->ok = true;
    $c->update(['status' => OrderConfirmationStatus::CalledConfirmed]);

    expect(app(TagSync::class)->push($c))->toBeTrue()
        ->and($tagger->calls[1]['tags'])->toBe(['wa-no-response', 'wa-confirmed'])
        ->and($c->fresh()->pending_tags)->toBeNull();
});

it('retries pending tags and gives up after the attempt cap', function () {
    $tagger = recordingTagger(false);
    $c = OrderConfirmation::factory()->status(OrderConfirmationStatus::CancelRequested)
        ->create(['pending_tags' => ['tags' => ['wa-cancel-request'], 'attempts' => TagSync::MAX_ATTEMPTS - 1]]);

    expect(app(TagSync::class)->retry($c))->toBeFalse()
        ->and($c->fresh()->pending_tags['attempts'])->toBe(TagSync::MAX_ATTEMPTS)
        ->and($c->fresh()->last_error)->toContain('wa-cancel-request');

    // At the cap the row is left for staff: no more calls.
    expect(app(TagSync::class)->retry($c->fresh()))->toBeFalse()
        ->and($tagger->calls)->toHaveCount(1);

    $tagger->ok = true;
    $fresh = OrderConfirmation::factory()->status(OrderConfirmationStatus::EditRequested)
        ->create(['pending_tags' => ['tags' => ['wa-edit-request'], 'attempts' => 2]]);

    expect(app(TagSync::class)->retry($fresh))->toBeTrue()
        ->and($fresh->fresh()->pending_tags)->toBeNull()
        ->and($fresh->fresh()->tag_synced_at)->not->toBeNull();
});

it('accepts a plain list in pending_tags', function () {
    $tagger = recordingTagger();
    $c = OrderConfirmation::factory()->status(OrderConfirmationStatus::Confirmed)->create(['pending_tags' => ['wa-confirmed']]);

    expect(app(TagSync::class)->retry($c))->toBeTrue()
        ->and($tagger->calls[0]['tags'])->toBe(['wa-confirmed']);
});
