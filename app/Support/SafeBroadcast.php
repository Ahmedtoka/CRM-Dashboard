<?php

namespace App\Support;

use App\Bot\Flows\Sandbox\SandboxMode;

/**
 * Real-time pushes are best-effort. All CRM events broadcast synchronously
 * (ShouldBroadcastNow), so an unreachable Reverb/Pusher server would otherwise
 * throw out of the service call *after* its database work has committed —
 * stranding queued outbound messages, silencing the bot, or failing an order
 * request that actually succeeded. Every broadcast goes through here: the
 * failure is reported (logged) and the caller carries on; clients catch up via
 * polling.
 */
final class SafeBroadcast
{
    public static function send(object $event): void
    {
        // A flow-designer sandbox run (FlowSandbox) never pushes real-time events.
        if (SandboxMode::active()) {
            return;
        }

        rescue(fn () => event($event), null, report: true);
    }
}
