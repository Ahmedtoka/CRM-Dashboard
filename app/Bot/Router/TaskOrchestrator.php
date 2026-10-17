<?php

namespace App\Bot\Router;

use App\Bot\Flows\EntityExtractor;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowLabels;
use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\FlowScripts;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\Returns\ReturnPrefill;
use App\Enums\Handler;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\Conversation;
use Illuminate\Support\Facades\Log;

/**
 * Runs the tasks of one message in order (bot-hybrid.md §3.1, §3.7): directed tasks (a return, an
 * exchange, a cancellation…) each start their guided flow already filled; when one flow ends the
 * next is offered («تمام، نكمل في مرتجع أوردر #209200؟» [أيوه] [بعدين]); at the end, with two
 * tasks or more (owner B7), «ملخص طلبات حضرتك:» lists what was done.
 *
 * A request already recorded for the same order (CaseLock, owner B6) is not started again: she is
 * told its number and the next task goes on.
 */
class TaskOrchestrator
{
    public const CONFIRM = 'task_next';

    public const YES_BUTTON = ['title' => 'أيوه', 'payload' => 'yes'];

    public const LATER_BUTTON = ['title' => 'بعدين', 'payload' => 'no'];

    public function __construct(private readonly OutboundService $outbound, private readonly FlowPrompter $prompter) {}

    /**
     * The directed tasks a flow request carries in her words: one per order she named (owner B6:
     * the same request for two orders is two requests), each filled from the message.
     *
     * @return list<array<string, mixed>>
     */
    public static function flowTasks(string $flowKey, string $text): array
    {
        if ($flowKey === 'return_exchange') {
            $prefill = ReturnPrefill::fromText($text);
            $refs = $prefill['order_refs'] !== [] ? $prefill['order_refs'] : [null];

            return array_map(fn (?string $ref, int $i) => TaskQueue::task(
                (string) ($prefill['request_kind'] ?? 'return_exchange'),
                self::label($flowKey, $prefill['request_kind'], $ref),
                $flowKey,
                ReturnPrefill::flowData($prefill, $i),
            ), $refs, array_keys($refs));
        }

        $refs = EntityExtractor::orderRefsInContext($text) ?: [null];

        return array_map(fn (?string $ref) => TaskQueue::task($flowKey, self::label($flowKey, null, $ref), $flowKey, array_filter(['order_ref_hint' => $ref])), $refs);
    }

    /** «مرتجع أوردر #209129», «استبدال», «طلب الإلغاء أو التعديل أوردر #209129». */
    public static function label(string $flowKey, ?string $kind, ?string $ref): string
    {
        $base = match ($kind) {
            'return' => 'مرتجع',
            'exchange' => 'استبدال',
            'mixed' => 'مرتجع واستبدال',
            default => FlowLabels::of($flowKey),
        };

        return $ref !== null ? $base.' أوردر #'.$ref : $base;
    }

    /**
     * Queues the tasks and starts the first one with a flow. $answered are the tasks this turn
     * already replied to (they count for the summary).
     *
     * @param  list<array<string, mixed>>  $tasks
     * @param  list<array<string, mixed>>  $answered
     * @return bool whether a flow started
     */
    public function start(Conversation $c, array $tasks, int $delayMs = 0, array $answered = []): bool
    {
        TaskQueue::put($c, [...$answered, ...$tasks]);

        return $this->startNext($c, $delayMs);
    }

    /** The next pending flow (a locked one is reported and skipped). False when none is left. */
    public function startNext(Conversation $c, int $delayMs = 0): bool
    {
        while (($next = TaskQueue::nextPending($c)) !== null) {
            $case = CaseLock::forFlow($c, (string) $next['flow'], $next['data']['order_ref_hint'] ?? null);

            if ($case === null) {
                TaskQueue::update($c, $next['id'], ['status' => TaskQueue::RUNNING]);
                app(FlowEngine::class)->start($c, (string) $next['flow'], ['task_id' => $next['id']] + (array) $next['data'], $delayMs);

                // A flow that did not begin (switched off, broken): skip it, never sit silent (review #8).
                $task = collect(TaskQueue::all($c->refresh()))->firstWhere('id', $next['id']);

                // Still running, already finished by the flow's own end, or the chat moved on (a question, a person).
                if (($task['status'] ?? null) !== TaskQueue::RUNNING || FlowState::flow($c) !== null || FlowState::confirm($c) !== null || $c->handler !== Handler::Bot) {
                    return true;
                }

                TaskQueue::update($c, $next['id'], ['status' => TaskQueue::SKIPPED]);

                continue;
            }

            $this->send($c, strtr($this->script('tasks_already_recorded', ['case_id' => $case->id]), [
                '{flow_label}' => self::label((string) $next['flow'], (string) $next['kind'], null),
                '{order_number}' => ltrim((string) ($next['data']['order_ref_hint'] ?? ''), '#'),
            ]), $delayMs);
            TaskQueue::update($c, $next['id'], ['status' => TaskQueue::DONE, 'case_id' => $case->id]);
        }

        $this->finish($c, $delayMs);

        return false;
    }

    /** FlowEngine: a flow reached its `end`. The running task is done; the next is offered. */
    public function flowEnded(Conversation $c, array $ended): void
    {
        $running = TaskQueue::running($c);

        // Only the flow this queue started (its task_id), never one she opened herself later (review #11).
        if ($running === null || ($running['id'] ?? null) !== ($ended['data']['task_id'] ?? null)) {
            return;
        }

        $caseId = is_numeric($ended['data']['case_id'] ?? null) ? (int) $ended['data']['case_id'] : null;
        TaskQueue::update($c, $running['id'], ['status' => $caseId !== null ? TaskQueue::DONE : TaskQueue::SKIPPED, 'case_id' => $caseId]);

        $next = TaskQueue::nextPending($c);

        if ($next === null) {
            $this->finish($c);

            return;
        }

        $this->send($c, str_replace('{next_label}', (string) $next['label'], $this->script('tasks_next_offer')), 0, [self::YES_BUTTON, self::LATER_BUTTON]);
        FlowState::setConfirm($c, self::CONFIRM);
    }

    /** Her answer to «نكمل في …؟»: the next task, or «بعدين» (the rest is skipped and summed up). */
    public function answerNext(Conversation $c, bool $yes): bool
    {
        FlowState::setConfirm($c, null);

        if ($yes) {
            $this->startNext($c);

            return true;
        }

        TaskQueue::skipPending($c);
        $this->send($c, $this->script('tasks_later'));
        $this->finish($c);

        return true;
    }

    /**
     * A person takes the chat in the middle of the tasks: what was done and what is still waiting,
     * as lines for the team's handover note; the queue is cleared (the person carries on).
     *
     * @return list<string>
     */
    public function handedOver(Conversation $c): array
    {
        $tasks = TaskQueue::all($c);

        if (count($tasks) < 2) {
            TaskQueue::clear($c);

            return [];
        }

        $lines = ['طلباتها في نفس الرسالة:'];

        foreach ($tasks as $t) {
            $lines[] = '- '.$t['label'].match ($t['status']) {
                TaskQueue::DONE => $t['case_id'] !== null ? ' — اتسجل طلب #'.$t['case_id'] : ' — اتسجل',
                TaskQueue::ANSWERED => ' — اتجاوب',
                TaskQueue::RUNNING => ' — كانت شغالة فيه وقت التحويل',
                default => ' — لسه',
            };
        }

        TaskQueue::clear($c);

        return $lines;
    }

    /**
     * «ملخص طلبات حضرتك:» with one line per task — only with two tasks or more (owner B7). No emoji
     * (spec 2026-10-01 §6).
     *
     * @param  list<array<string, mixed>>  $tasks
     */
    public function summaryText(array $tasks): ?string
    {
        if (count($tasks) < 2) {
            return null;
        }

        $lines = [];

        foreach (array_values($tasks) as $i => $t) {
            $line = ($i + 1).') '.$t['label'];
            $line .= match ($t['status']) {
                TaskQueue::DONE => $t['case_id'] !== null ? ' — طلب #'.$t['case_id'] : ' — اتسجل',
                TaskQueue::ANSWERED => filled($t['line'] ?? null) ? ': '.$t['line'] : ' (اتبعت فوق)',
                default => ' — لسه مكملناهوش',
            };
            $lines[] = $line;
        }

        return $this->script('tasks_summary_title')."\n".implode("\n", $lines);
    }

    /** The summary (two tasks or more), then the queue is cleared. */
    private function finish(Conversation $c, int $delayMs = 0): void
    {
        $summary = $this->summaryText(TaskQueue::all($c));

        if ($summary !== null) {
            $this->send($c, $summary, $delayMs);
        }

        TaskQueue::clear($c);
    }

    private function script(string $key, array $data = []): string
    {
        return $this->prompter->script($key, $data)
            ?? str_replace('{case_id}', (string) ($data['case_id'] ?? ''), (string) (FlowScripts::all()[$key]['body'] ?? ''));
    }

    /** @param  list<array{title:string, payload:string}>  $buttons */
    private function send(Conversation $c, string $text, int $delayMs = 0, array $buttons = []): void
    {
        if (trim($text) === '') {
            return;
        }

        try {
            $this->outbound->sendBot($c, $text, $delayMs, true, $buttons);
        } catch (WindowClosedException) {
            Log::info('tasks.window_closed', ['conversation_id' => $c->id]);
        }
    }
}
