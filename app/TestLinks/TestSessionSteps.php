<?php

namespace App\TestLinks;

use App\Models\BotTestSession;
use App\Models\BotTestSessionStep;
use App\Models\Conversation;
use Throwable;

/**
 * The trail a tester leaves through the guided flows (design 2026-09-21 §4).
 *
 * Called from App\Bot\Flows\FlowState — the single place the bot moves a
 * conversation into, through and out of a flow — and does nothing at all unless
 * the conversation belongs to a team test link. Recording never throws: a
 * report detail must not be able to break a customer's turn.
 */
final class TestSessionSteps
{
    /** Remembers the last row written per conversation, so a repeated step is not stored twice. */
    private static array $last = [];

    public static function enter(Conversation $conversation, string $flowKey, string $stepId): void
    {
        self::write($conversation, $flowKey, $stepId !== '' ? $stepId : null);
    }

    /** The flow ended (or was abandoned): a row with no step closes the trail. */
    public static function leave(Conversation $conversation, ?string $flowKey): void
    {
        if ($flowKey === null || $flowKey === '') {
            return;
        }

        self::write($conversation, $flowKey, null);
    }

    public static function reset(): void
    {
        self::$last = [];
    }

    private static function write(Conversation $conversation, string $flowKey, ?string $stepId): void
    {
        try {
            if (! $conversation->is_test) {
                return;
            }

            $fingerprint = $flowKey.'|'.($stepId ?? '');

            if ((self::$last[$conversation->id] ?? null) === $fingerprint) {
                return;
            }

            $sessionId = BotTestSession::query()
                ->where('conversation_id', $conversation->id)
                ->orderByDesc('id')
                ->value('id');

            if ($sessionId === null) {
                return;
            }

            BotTestSessionStep::create([
                'bot_test_session_id' => $sessionId,
                'flow_key' => $flowKey,
                'step_id' => $stepId,
                'entered_at' => now(),
            ]);

            if (count(self::$last) > 500) {
                self::$last = [];
            }

            self::$last[$conversation->id] = $fingerprint;
        } catch (Throwable $e) {
            report($e);
        }
    }
}
