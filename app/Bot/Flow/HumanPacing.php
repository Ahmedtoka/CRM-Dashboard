<?php

namespace App\Bot\Flow;

use App\Models\Conversation;
use Illuminate\Support\Sleep;

/** Makes bot replies arrive like a person typing (spec §2.1). */
class HumanPacing
{
    public function delayMs(string $text, int $msPerChar): int
    {
        if ($msPerChar <= 0) {
            return 0;
        }

        return (int) max(1500, min(6000, mb_strlen($text) * $msPerChar));
    }

    /** @return list<string> */
    public function split(string $text): array
    {
        $text = trim($text);

        if (mb_strlen($text) <= 450) {
            return [$text];
        }

        $head = mb_substr($text, 0, 450);
        $pos = mb_strrpos($head, "\n\n");

        if ($pos === false || $pos < 50) {
            return [$text];
        }

        return [trim(mb_substr($text, 0, $pos)), trim(mb_substr($text, $pos))];
    }

    /** Typing on, wait the remaining delay (minus ms already spent), best effort. */
    public function beforeSend(Conversation $c, string $text, int $msPerChar, int $alreadySpentMs = 0): void
    {
        $delay = $this->delayMs($text, $msPerChar) - $alreadySpentMs;

        rescue(fn () => app(ReplyScheduler::class)->typing($c, true), report: false);

        if ($delay > 0) {
            Sleep::for($delay)->milliseconds();
        }
    }
}
