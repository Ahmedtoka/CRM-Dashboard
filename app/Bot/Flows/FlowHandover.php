<?php

namespace App\Bot\Flows;

use App\Bot\BotEngine;
use App\Models\Conversation;

/**
 * The handover a guided flow triggers. Live it is BotEngine::handover
 * (resolved lazily: BotEngine's turn pipeline depends on FlowEngine); the
 * sandbox swaps in SandboxHandover, which only records an event.
 */
class FlowHandover
{
    /** @param  array{priority?:string, queue?:string, category?:string, summary_extra?:list<string>}  $routing */
    public function handover(Conversation $c, string $reason, ?string $customerText, array $routing): void
    {
        app(BotEngine::class)->handover($c, $reason, $customerText, null, null, $routing);
    }
}
