<?php

namespace App\Bot\Brain\Trace;

use App\Bot\Agent\AgentOutcome;
use App\Bot\Agent\ReplyGuards;

/**
 * The reply guards, with their verdict written to the turn's trace (b2). Bound in place of
 * ReplyGuards so the guards themselves stay untouched.
 */
class TracedReplyGuards extends ReplyGuards
{
    /** @param  list<string>  $customerTexts */
    public function failure(AgentOutcome $o, array $customerTexts = []): ?string
    {
        $failure = parent::failure($o, $customerTexts);

        if ($o->reply !== '') {
            app(TurnTrace::class)->recordGuard($failure);
        }

        return $failure;
    }
}
