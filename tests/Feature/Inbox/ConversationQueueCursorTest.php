<?php

use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\User;

/*
 * Task 4a: the queue ordering (priority, oldest customer message, id) pages through
 * cursor pagination without duplicates or gaps (it used a raw CASE ORDER BY before).
 */
it('pages the whole queue_all list through next_cursor with no duplicates and none missing', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $account = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    $levels = ['high', 'medium', 'low', null];
    $base = now()->subDays(2)->startOfMinute();

    for ($i = 0; $i < 70; $i++) {
        Conversation::factory()->for($account, 'channelAccount')->create([
            'needs_human' => true,
            'priority_level' => $levels[$i % 4],
            // Some equal timestamps on purpose: the id breaks the tie.
            'last_customer_message_at' => $base->copy()->addMinutes(($i * 7) % 23),
        ]);
    }

    $expected = Conversation::query()
        ->orderBy('priority_rank')->orderBy('last_customer_message_at')->orderBy('id')
        ->pluck('id')->all();

    $seen = [];
    $url = '/inbox/conversations?flags=queue_all';
    $pages = 0;
    do {
        $res = $this->actingAs($admin)->getJson($url)->assertOk();
        $seen = array_merge($seen, $res->json('data.*.id'));
        $cursor = $res->json('meta.next_cursor');
        $url = '/inbox/conversations?flags=queue_all&cursor='.$cursor;
        $pages++;
    } while ($cursor !== null && $pages < 10);

    expect($pages)->toBe(3)
        ->and($seen)->toBe($expected)
        ->and(count(array_unique($seen)))->toBe(70);
});

it('pages assignee=<id> (R3) through next_cursor in list order, in both orderings', function (string $extra, Closure $order) {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $other = User::factory()->create(['role' => UserRole::Moderator]);
    $account = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    $base = now()->subDays(2)->startOfMinute();

    for ($i = 0; $i < 150; $i++) {
        $kind = $i % 3;
        Conversation::factory()->for($account, 'channelAccount')->create([
            'needs_human' => true,
            'priority_level' => ['high', 'medium', null][$i % 3 === 0 ? 0 : ($i % 2)],
            // 0: assigned to her; 1: unassigned, she replied last; 2: assigned to another, she replied last (not hers).
            'assignee_id' => $kind === 0 ? $mod->id : ($kind === 2 ? $other->id : null),
            'last_responder_id' => $kind === 0 ? null : $mod->id,
            'last_message_at' => $base->copy()->addMinutes(($i * 7) % 17),
            'last_customer_message_at' => $base->copy()->addMinutes(($i * 5) % 13),
        ]);
    }

    $expected = $order(Conversation::query()->where(fn ($w) => $w->where('assignee_id', $mod->id)
        ->orWhere(fn ($x) => $x->whereNull('assignee_id')->where('last_responder_id', $mod->id))))
        ->pluck('id')->all();

    $seen = [];
    $url = "/inbox/conversations?assignee={$mod->id}{$extra}";
    $pages = 0;
    do {
        $res = $this->actingAs($admin)->getJson($url)->assertOk();
        $seen = array_merge($seen, $res->json('data.*.id'));
        $cursor = $res->json('meta.next_cursor');
        $url = "/inbox/conversations?assignee={$mod->id}{$extra}&cursor={$cursor}";
        $pages++;
    } while ($cursor !== null && $pages < 10);

    expect($pages)->toBe(4)->and($expected)->toHaveCount(100)->and($seen)->toBe($expected);

    // And back: the previous cursor of the last page returns the first page again.
    $prev = $res->json('meta.prev_cursor');
    $back = $this->actingAs($admin)->getJson("/inbox/conversations?assignee={$mod->id}{$extra}&cursor={$prev}")->assertOk();
    expect($back->json('data.*.id'))->toBe(array_slice($expected, 60, 30));
})->with([
    'list order' => ['', fn ($q) => $q->orderByDesc('last_message_at')->orderByDesc('id')],
    'queue order' => ['&flags=queue_all', fn ($q) => $q->orderBy('priority_rank')->orderBy('last_customer_message_at')->orderBy('id')],
]);
