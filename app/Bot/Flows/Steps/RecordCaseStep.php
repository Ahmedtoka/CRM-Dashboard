<?php

namespace App\Bot\Flows\Steps;

use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\FlowScripts;
use App\Cases\CaseRecorder;
use App\Models\Conversation;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records the flow's data as a SupportCase of the step's `case_type`, keeps
 * `data.case_id`, sends the step's closing `script` with `{case_id}` rendered
 * and continues (the conversation stays with the bot). If recording fails the
 * customer is handed to a person instead of being told a number that does
 * not exist.
 */
final class RecordCaseStep extends BaseStep
{
    public function __construct(FlowPrompter $prompter, private readonly CaseRecorder $recorder)
    {
        parent::__construct($prompter);
    }

    public function enter(Conversation $c, array $state, array $step): StepOutcome
    {
        try {
            $case = $this->recorder->record($c, (string) $step['case_type'], $state['data']);
        } catch (Throwable $e) {
            Log::error('flow.case_record_failed', ['conversation_id' => $c->id, 'flow' => $state['key'] ?? null, 'error' => $e->getMessage()]);

            return StepOutcome::handover('human_request');
        }

        $data = ['case_id' => $case->id] + $state['data'];
        $key = (string) ($step['script'] ?? '');
        $text = $key === '' ? '' : ($this->prompter->script($key, $data)
            ?? str_replace('{case_id}', (string) $case->id, (string) (FlowScripts::all()[$key]['body'] ?? '')));

        return StepOutcome::continue(['case_id' => $case->id], $text !== '' ? [['text' => $text]] : []);
    }
}
