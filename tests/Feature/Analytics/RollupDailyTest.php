<?php

use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Models\AnalyticsDaily;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;

it('rolls up a Cairo day per user and platform plus bot rows, idempotently', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-11 12:00:00', 'UTC'));
    $mod = User::factory()->create();
    $account = ChannelAccount::factory()->create(['platform' => Platform::Instagram]);
    $c = Conversation::factory()->create(['channel_account_id' => $account->id]);

    // 2026-09-09 22:30 UTC is 2026-09-10 in Cairo.
    foreach (['2026-09-09 22:30:00', '2026-09-10 10:00:00'] as $at) {
        Message::factory()->create(['conversation_id' => $c->id, 'direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'user_id' => $mod->id, 'created_at' => $at]);
    }
    Message::factory()->create(['conversation_id' => $c->id, 'direction' => MessageDirection::Out, 'sender_type' => SenderType::Bot, 'created_at' => '2026-09-10 10:05:00']);
    // Outside the Cairo day.
    Message::factory()->create(['conversation_id' => $c->id, 'direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'user_id' => $mod->id, 'created_at' => '2026-09-10 22:30:00']);

    $this->artisan('crm:rollup', ['date' => '2026-09-10'])->assertSuccessful();
    $this->artisan('crm:rollup', ['date' => '2026-09-10'])->assertSuccessful();

    $rows = AnalyticsDaily::whereDate('date', '2026-09-10')->get();

    $userTotal = $rows->first(fn ($r) => $r->user_id === $mod->id && $r->platform === null);
    $userIg = $rows->first(fn ($r) => $r->user_id === $mod->id && $r->platform === Platform::Instagram);
    $botTotal = $rows->first(fn ($r) => $r->user_id === null && $r->platform === null);

    expect($rows)->toHaveCount(4)
        ->and($userTotal->messages_sent)->toBe(2)
        ->and($userTotal->conversations_handled)->toBe(1)
        ->and($userIg->messages_sent)->toBe(2)
        ->and($botTotal->messages_sent)->toBe(1);
});

it('schedules crm:rollup in Africa/Cairo', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'crm:rollup'));

    expect($events)->toHaveCount(2)
        ->and($events->pluck('timezone')->unique()->all())->toBe(['Africa/Cairo'])
        ->and($events->pluck('expression')->all())->toContain('10 0 * * *', '*/15 * * * *');
});
