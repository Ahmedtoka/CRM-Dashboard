<?php

namespace App\Bot\Flows\Sandbox;

use App\Bot\Language\OutboundTranslation;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Inbox\OutboundService;
use App\Models\Conversation;
use App\Models\Message;

/**
 * OutboundService stand-in for a sandbox run: records bot texts, buttons and cards
 * and returns unsaved messages. It never checks windows, queues jobs, calls
 * channel adapters or broadcasts. The parent constructor is deliberately not
 * called — only sendBot (and sendSystem, for safety) are reachable from the
 * flow engine with the sandbox bindings in place.
 */
class SandboxOutbound extends OutboundService
{
    public function __construct(private readonly SandboxLog $log) {}

    public function sendBot(Conversation $c, string $body, int $delayMs = 0, bool $skipIfHumanTookOver = false, array $buttons = [], ?array $cards = null): Message
    {
        // The same language gate as the real send (design 2026-09-21 §1-2), so a
        // simulated English run says exactly what a real English customer would read.
        [$body, $buttons, $cards] = app(OutboundTranslation::class)->apply($c, $body, $buttons, $cards);

        $this->log->message($body, $buttons, $cards);

        $message = $this->unsaved($c, SenderType::Bot, $body, $buttons);
        $message->cards = $cards;

        return $message;
    }

    public function sendSystem(Conversation $c, string $body): Message
    {
        return $this->unsaved($c, SenderType::System, $body);
    }

    private function unsaved(Conversation $c, SenderType $sender, string $body, array $buttons = []): Message
    {
        return new Message([
            'conversation_id' => $c->id,
            'platform' => $c->platform,
            'direction' => MessageDirection::Out,
            'sender_type' => $sender,
            'body' => $body,
            'buttons' => $buttons !== [] ? $buttons : null,
        ]);
    }
}
