<?php

namespace App\Bot\Brain\Console;

use App\Bot\Flows\Sandbox\SandboxOutbound;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;

/**
 * The test console's outbound (b2): the flow sandbox's recorder, plus the bot's other sends (the size
 * chart picture, a WhatsApp template) recorded as text lines instead of reaching the parent, which
 * a sandbox never constructs.
 */
class ConsoleOutbound extends SandboxOutbound
{
    public function sendBotAttachment(Conversation $c, MessageAttachment $attachment, ?string $caption = null): Message
    {
        return $this->sendBot($c, trim('['.($attachment->type?->value ?? 'file').'] '.(string) $caption));
    }

    public function sendTemplate(Conversation $c, string $body, array $template): Message
    {
        return $this->sendBot($c, $body);
    }
}
