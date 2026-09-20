<?php

namespace App\Bot\Flows;

use App\Models\Conversation;
use App\TestLinks\TestSessionSteps;

/**
 * Reads and writes the flow part of `conversations.bot_state`:
 * `flow = {key, step, data, retries, started_at}` and `flow_confirm`.
 * Every write merges into the existing state so other keys survive.
 */
final class FlowState
{
    /** @return array{key:string, step:string, data:array, retries:int, started_at:?string}|null */
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
            'started_at' => $flow['started_at'] ?? null,
        ];
    }

    public static function put(Conversation $c, array $flow): void
    {
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
