<?php

namespace App\Bot\Flows\Sandbox;

use App\Cases\CaseRecorder;
use App\Models\Conversation;
use App\Models\SupportCase;

/**
 * CaseRecorder stand-in for a sandbox run: records a `case` event and returns
 * an unsaved SupportCase with the fake id 0 (closing scripts render `#0`).
 * No row, notification or broadcast. The parent constructor is not called.
 */
class SandboxCaseRecorder extends CaseRecorder
{
    public function __construct(private readonly SandboxLog $log) {}

    public function record(Conversation $c, string $type, array $data): SupportCase
    {
        $this->log->event('case', 'هيتسجل حالة: '.(SupportCase::TYPE_LABELS[$type] ?? $type), $data);

        $case = new SupportCase(['type' => $type, 'data' => $data]);
        $case->setAttribute('id', 0);

        return $case;
    }
}
