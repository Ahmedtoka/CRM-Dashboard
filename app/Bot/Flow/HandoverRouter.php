<?php

namespace App\Bot\Flow;

use App\Bot\BotEngine;
use App\Models\Conversation;

/**
 * Wraps `BotEngine::handover` with priority/queue routing (spec §2.1
 * HandoverRouter): stamps the conversation's `priority_level`, `queue` and
 * `handover_category`, and hands the notification audience/type plus the
 * internal note's extra lines through to `BotEngine::handover`'s `$routing`.
 */
final class HandoverRouter
{
    public function __construct(private readonly BotEngine $engine) {}

    /**
     * @param  array{priority:string, queue:string, category:string, reason?:string}  $routing
     * @param  list<string>  $summaryExtra  extra internal-note lines: collected details, order line(s), window line
     */
    public function route(Conversation $c, array $routing, string $customerText, array $summaryExtra = []): void
    {
        $this->engine->handover(
            $c,
            (string) ($routing['reason'] ?? $routing['category']),
            $customerText,
            null,
            null,
            $routing + ['summary_extra' => $summaryExtra],
        );
    }
}
