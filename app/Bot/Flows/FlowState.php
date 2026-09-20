<?php

namespace App\Bot\Flows;

use App\Models\Conversation;
use App\TestLinks\TestSessionSteps;
use Illuminate\Support\Carbon;

/**
 * Reads and writes the flow part of `conversations.bot_state`:
 * `flow = {key, step, data, retries, detours, started_at, last_at}` and `flow_confirm`.
 * Every write merges into the existing state so other keys survive.
 *
 * `detours` counts the questions answered in the middle of this flow and `last_at` when
 * it last moved, both for the off-flow handling of design 2026-09-21 §6.
 */
final class FlowState
{
    /** @return array{key:string, step:string, data:array, retries:int, detours:int, started_at:?string, last_at:?string}|null */
    public static function flow(Conversation $c): ?array
    {
        $flow = ($c->bot_state ?? [])['flow'] ?? null;

        if (! is_array($flow) || ! is_string($flow['key'] ?? null) || $flow['key'] === '') {
            return null;
        }

        return [
            'key' => $flow['key'],
            'step' => (string) ($flow['step'] ?? ''),
            'data' => is_array($flow['data'] ?? null) ? $flow['data'] : [],
            'retries' => (int) ($flow['retries'] ?? 0),
            'detours' => (int) ($flow['detours'] ?? 0),
            'started_at' => $flow['started_at'] ?? null,
            'last_at' => $flow['last_at'] ?? ($flow['started_at'] ?? null),
        ];
    }

    /** Minutes since the flow last moved (null when it never has). */
    public static function idleMinutes(Conversation $c): ?float
    {
        $at = self::flow($c)['last_at'] ?? null;

        if (! is_string($at) || $at === '') {
            return null;
        }

        return rescue(fn () => Carbon::parse($at)->diffInMinutes(now(), absolute: true), null, report: false);
    }

    public static function put(Conversation $c, array $flow): void
    {
        $flow['last_at'] = now()->toIso8601String();
        $c->forceFill(['bot_state' => array_merge($c->bot_state ?? [], ['flow' => $flow])])->save();

        // The funnel of the team test links (design 2026-09-21 §4) is built from here:
        // this is the one place a conversation moves into or through a flow.
        TestSessionSteps::enter($c, (string) ($flow['key'] ?? ''), (string) ($flow['step'] ?? ''));
    }

    public static function confirm(Conversation $c): ?string
    {
        $v = ($c->bot_state ?? [])['flow_confirm'] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    public static function setConfirm(Conversation $c, ?string $confirm): void
    {
        $state = $c->bot_state ?? [];

        if (($state['flow_confirm'] ?? null) === $confirm) {
            return;
        }

        if ($confirm === null) {
            unset($state['flow_confirm']);
        } else {
            $state['flow_confirm'] = $confirm;
        }

        $c->forceFill(['bot_state' => $state !== [] ? $state : null])->save();
    }

    public static function clear(Conversation $c): void
    {
        $state = $c->bot_state ?? [];
        $leaving = is_array($state['flow'] ?? null) ? ($state['flow']['key'] ?? null) : null;
        unset($state['flow'], $state['flow_confirm']);

        $c->forceFill(['bot_state' => $state !== [] ? $state : null])->save();

        TestSessionSteps::leave($c, is_string($leaving) ? $leaving : null);
    }
}
