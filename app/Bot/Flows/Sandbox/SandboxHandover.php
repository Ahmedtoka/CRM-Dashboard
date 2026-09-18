<?php

namespace App\Bot\Flows\Sandbox;

use App\Bot\Flows\FlowHandover;
use App\Enums\Handler;
use App\Models\Conversation;

/** FlowHandover stand-in for a sandbox run: records a `handover` event and flips the handler in memory only. */
class SandboxHandover extends FlowHandover
{
    public function __construct(private readonly SandboxLog $log) {}

    public function handover(Conversation $c, string $reason, ?string $customerText, array $routing): void
    {
        $this->log->event('handover', 'هيتحول لموظف', ['reason' => $reason, 'category' => $routing['category'] ?? $reason]);

        $c->handler = Handler::Human;
    }
}
