<?php

use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Tag;
use App\Models\User;

it('refuses outside local/staging even with a load-named database', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['database.connections.sqlite.database' => 'social_crm_load']);

    $this->artisan('crm:seed-load-dataset', ['--conversations' => 5, '--messages-per' => 2])
        ->assertFailed();

    expect(Customer::count())->toBe(0);
});

it('refuses on local/staging when the database name does not contain "load"', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['database.connections.sqlite.database' => 'social_crm']);

    $this->artisan('crm:seed-load-dataset', ['--conversations' => 5, '--messages-per' => 2])
        ->assertFailed();

    expect(Customer::count())->toBe(0);
});

it('seeds the requested dataset and prints the counts inserted when allowed', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['database.connections.sqlite.database' => 'social_crm_load']);

    $this->artisan('crm:seed-load-dataset', ['--conversations' => 5, '--messages-per' => 2])
        ->assertSuccessful()
        ->expectsOutputToContain('conversations=5')
        ->expectsOutputToContain('messages=10');

    expect(Customer::count())->toBe(5)
        ->and(CustomerIdentity::count())->toBe(5)
        ->and(Conversation::count())->toBe(5)
        ->and(Message::count())->toBe(10);
});

it('allows staging too, not only local', function () {
    app()->detectEnvironment(fn () => 'staging');
    config(['database.connections.sqlite.database' => 'social_crm_load']);

    $this->artisan('crm:seed-load-dataset', ['--conversations' => 2, '--messages-per' => 1])
        ->assertSuccessful();

    expect(Conversation::count())->toBe(2);
});

it('gives seeded customers a realistic, deterministic distribution of order flags', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['database.connections.sqlite.database' => 'social_crm_load']);

    $this->artisan('crm:seed-load-dataset', ['--conversations' => 1000, '--messages-per' => 1])
        ->assertSuccessful();

    $total = Customer::count();
    expect($total)->toBe(1000);

    $pct = fn (string $column) => Customer::where($column, true)->count() / $total * 100;

    // Plan-mandated targets: is_repeat ~30%, has_open_order ~20%, has_return ~5%,
    // has_stuck_order ~3%, within ±2 percentage points.
    expect($pct('is_repeat'))->toBeGreaterThanOrEqual(28.0)->toBeLessThanOrEqual(32.0)
        ->and($pct('has_open_order'))->toBeGreaterThanOrEqual(18.0)->toBeLessThanOrEqual(22.0)
        ->and($pct('has_return'))->toBeGreaterThanOrEqual(3.0)->toBeLessThanOrEqual(7.0)
        ->and($pct('has_stuck_order'))->toBeGreaterThanOrEqual(1.0)->toBeLessThanOrEqual(5.0);
});

it('seeds moderators, spread dates, a hot conversation, notes and tags when asked', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['database.connections.sqlite.database' => 'social_crm_load']);

    $this->artisan('crm:seed-load-dataset', [
        '--conversations' => 40, '--messages-per' => 2, '--spread-days' => 30,
        '--moderators' => 3, '--hot' => 120, '--notes-pct' => 25, '--tags' => 4,
    ])->assertSuccessful()->expectsOutputToContain('hot=');

    $mods = User::where('email', 'like', 'load-mod-%@load.test')->get();
    expect($mods)->toHaveCount(3)
        ->and($mods->every(fn ($u) => $u->role->value === 'moderator'))->toBeTrue();

    // Dates are spread, not all "now": at least 10 distinct days among 40 rows.
    $days = Conversation::query()->pluck('last_message_at')->map(fn ($d) => substr((string) $d, 0, 10))->unique();
    expect($days->count())->toBeGreaterThanOrEqual(10);

    // Responders/assignees come from the seeded moderators only.
    expect(Conversation::whereNotNull('last_responder_id')->whereNotIn('last_responder_id', $mods->pluck('id'))->count())->toBe(0)
        ->and(Conversation::whereNotNull('assignee_id')->count())->toBeGreaterThan(0);

    $hot = Conversation::query()->withCount('messages')->orderByDesc('messages_count')->first();
    expect($hot->messages_count)->toBe(120)
        ->and(MessageAttachment::whereIn('message_id', $hot->messages()->pluck('id'))->count())->toBe(10)
        ->and(ConversationNote::where('conversation_id', $hot->id)->count())->toBe(12);

    // 25% of the 40 normal rows ($n % 100 < 25 -> n 0..24) plus the hot conversation's 12.
    expect(ConversationNote::count())->toBe(12 + 25)
        ->and(Tag::count())->toBe(4)
        ->and(DB::table('conversation_tag')->count())->toBeGreaterThan(0);
});
