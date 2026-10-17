<?php

namespace App\Confirmations;

use App\Models\OrderConfirmation;

/**
 * Quick-reply button payloads on the confirmation templates: `oc:{id}:{action}`.
 */
final class ConfirmationPayload
{
    private const PREFIX = 'oc';

    public static function make(int $confirmationId, string $action): string
    {
        return self::PREFIX.':'.$confirmationId.':'.$action;
    }

    /** @return array{id: int, action: string}|null */
    public static function parse(?string $payload): ?array
    {
        if ($payload === null || ! preg_match('/^oc:(\d{1,19}):([a-z]+)$/', $payload, $m)) {
            return null;
        }

        if (! in_array($m[2], OrderConfirmation::ANSWERS, true)) {
            return null;
        }

        return ['id' => (int) $m[1], 'action' => $m[2]];
    }
}
