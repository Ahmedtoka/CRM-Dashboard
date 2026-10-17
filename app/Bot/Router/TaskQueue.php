<?php

namespace App\Bot\Router;

use App\Models\Conversation;
use Illuminate\Support\Carbon;

/**
 * Several requests in one message (bot-hybrid.md §3.7, R5): the turn's tasks live in
 * `conversations.bot_state.tasks` = {items: list<task>, at}. A task is
 *
 *   {id, kind, flow: ?string, label, data: array (the flow's prefill), status, case_id: ?int, line: ?string}
 *
 * status: pending → running (its flow is going) → done (case recorded) | answered (replied in the
 * turn, no flow) | skipped («بعدين», or handed to a person). Only one task runs at a time; the
 * orchestrator starts the next one when a flow ends. Writes merge into bot_state like FlowState.
 */
final class TaskQueue
{
    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const ANSWERED = 'answered';

    public const SKIPPED = 'skipped';

    /** Tasks older than this are forgotten (she left them; review #11). */
    public const TTL_HOURS = 6;

    /** @return list<array<string, mixed>> */
    public static function all(Conversation $c): array
    {
        $tasks = ($c->bot_state ?? [])['tasks'] ?? [];
        $items = $tasks['items'] ?? [];
        $at = is_string($tasks['at'] ?? null) ? rescue(fn () => Carbon::parse($tasks['at']), null, report: false) : null;

        if ($at === null || $at->lt(now()->subHours(self::TTL_HOURS))) {
            return [];
        }

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /** @param  list<array<string, mixed>>  $tasks */
    public static function put(Conversation $c, array $tasks): void
    {
        $state = $c->bot_state ?? [];

        if ($tasks === []) {
            unset($state['tasks']);
        } else {
            $state['tasks'] = ['items' => array_values($tasks), 'at' => now()->toIso8601String()];
        }

        $c->forceFill(['bot_state' => $state !== [] ? $state : null])->save();
    }

    public static function clear(Conversation $c): void
    {
        self::put($c, []);
    }

    /** A new task (status pending). */
    public static function task(string $kind, string $label, ?string $flow = null, array $data = []): array
    {
        return ['id' => substr(md5(uniqid($kind, true)), 0, 8), 'kind' => $kind, 'flow' => $flow, 'label' => $label, 'data' => $data, 'status' => self::PENDING, 'case_id' => null, 'line' => null];
    }

    /** @return array<string, mixed>|null */
    public static function running(Conversation $c): ?array
    {
        return collect(self::all($c))->firstWhere('status', self::RUNNING);
    }

    /** @return array<string, mixed>|null the next task with a flow still to run */
    public static function nextPending(Conversation $c): ?array
    {
        return collect(self::all($c))->first(fn ($t) => $t['status'] === self::PENDING && filled($t['flow'] ?? null));
    }

    /** Updates one task by id. */
    public static function update(Conversation $c, string $id, array $changes): void
    {
        self::put($c, array_map(fn (array $t) => ($t['id'] ?? null) === $id ? array_merge($t, $changes) : $t, self::all($c)));
    }

    /** Every task still pending becomes skipped (she said «بعدين», or a person took over). */
    public static function skipPending(Conversation $c): void
    {
        self::put($c, array_map(fn (array $t) => in_array($t['status'], [self::PENDING, self::RUNNING], true) ? ['status' => self::SKIPPED] + $t : $t, self::all($c)));
    }
}
