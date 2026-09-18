<?php

namespace App\Bot\Flows\Sandbox;

/**
 * Set while FlowSandbox::run executes. SafeBroadcast::send returns early
 * while it is active so a simulated turn can never push a real-time event.
 */
final class SandboxMode
{
    private static bool $active = false;

    public static function active(): bool
    {
        return self::$active;
    }

    public static function set(bool $active): void
    {
        self::$active = $active;
    }
}
