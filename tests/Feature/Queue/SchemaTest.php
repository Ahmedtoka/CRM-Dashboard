<?php

// tests/Feature/Queue/SchemaTest.php
use App\Models\Conversation;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;

it('creates the queue tables with defaults', function () {
    $s = QueueSetting::current();
    expect($s->id)->toBe(1)->and($s->enabled)->toBeFalse()->and($s->windows_per_moderator)->toBe(3)
        ->and($s->points['inquiry'])->toBe(8)->and($s->shifts[0]['key'])->toBe('morning');
    $shift = Shift::factory()->create();
    $m = ShiftMember::factory()->for($shift)->create();
    $e = QueueEntry::factory()->create(['shift_id' => $shift->id, 'assigned_user_id' => $m->user_id]);
    expect($e->conversation)->toBeInstanceOf(Conversation::class)->and($e->status)->toBe('waiting')
        ->and($e->conversation->fresh()->assignee)->toBeNull();
    $e->conversation->update(['assignee_id' => $m->user_id]);
    expect($e->conversation->fresh()->assignee)->toBeInstanceOf(User::class);
});

it('keeps one settings row with id 1 even when the auto-increment has moved on', function () {
    QueueSetting::query()->forceCreate(['id' => 5])->delete();

    $first = QueueSetting::current();
    $first->update(['silence_close_seconds' => 420]);

    expect($first->id)->toBe(1)
        ->and(QueueSetting::current()->silence_close_seconds)->toBe(420)
        ->and(QueueSetting::query()->count())->toBe(1)
        ->and(QueueSetting::current()->windows_per_moderator)->toBe(3);
});
