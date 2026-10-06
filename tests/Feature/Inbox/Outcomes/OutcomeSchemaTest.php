<?php

use App\Models\Conversation;
use App\Models\ConversationOutcome;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

it('creates the outcomes table with the contract columns', function () {
    expect(Schema::hasColumns('conversation_outcomes', [
        'conversation_id', 'episode_key', 'first_message_id', 'last_message_id', 'queue_entry_id', 'order_id',
        'outcome', 'note', 'source', 'set_by_id', 'set_at', 'started_at', 'ended_at', 'ended_by', 'reached_agent',
    ]))->toBeTrue();
});

it('keeps one row per conversation episode and reads back through the conversation', function () {
    $c = Conversation::factory()->create();
    $row = ConversationOutcome::create(['conversation_id' => $c->id, 'episode_key' => 'm1', 'outcome' => 'price', 'source' => 'agent', 'set_at' => now()]);

    expect($c->outcomes()->pluck('id')->all())->toBe([$row->id])
        ->and($row->fresh()->reached_agent)->toBeFalse()
        ->and($row->outcomeEnum()->value)->toBe('price');

    ConversationOutcome::create(['conversation_id' => $c->id, 'episode_key' => 'm1', 'outcome' => 'other', 'source' => 'agent', 'set_at' => now()]);
})->throws(UniqueConstraintViolationException::class);

it('rolls back cleanly', function () {
    Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_10_08_300010_create_conversation_outcomes_table.php']);
    expect(Schema::hasTable('conversation_outcomes'))->toBeFalse();
    Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_08_300010_create_conversation_outcomes_table.php']);
    expect(Schema::hasTable('conversation_outcomes'))->toBeTrue();
});
