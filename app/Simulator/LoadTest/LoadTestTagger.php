<?php

namespace App\Simulator\LoadTest;

use App\Models\Conversation;
use App\Models\Message;

/**
 * Marks the conversation a load-test opener opened (`conversations.meta.load_test`): which run
 * and scenario it plays, and how many follow-ups were sent. Only a load-test channel's message
 * is ever tagged, and an existing tag is never overwritten (a later message of the same chat
 * keeps its scenario and step).
 */
final class LoadTestTagger
{
    /**
     * @param  array<string, mixed>|null  $tag  {run, scenario, name, customer_key, seeded?}
     */
    public static function tag(?Message $message, mixed $tag): ?Message
    {
        if ($message === null || ! is_array($tag) || ! isset($tag['run'], $tag['scenario'])) {
            return $message;
        }

        $c = Conversation::query()->with('channelAccount')->find($message->conversation_id);

        if ($c === null || ! $c->channelAccount?->is_load_test || isset(($c->meta ?? [])['load_test'])) {
            return $message;
        }

        $c->forceFill(['meta' => array_merge($c->meta ?? [], ['load_test' => [
            'run' => (int) $tag['run'],
            'scenario' => (string) $tag['scenario'],
            'name' => (string) ($tag['name'] ?? ''),
            'customer_key' => (string) ($tag['customer_key'] ?? ''),
            'step' => 0,
            'pending' => null,
        ]])])->save();

        return $message;
    }
}
