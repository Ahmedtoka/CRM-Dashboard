<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Resources\ConversationResource;
use App\Http\Resources\QueueEntryResource;
use App\Inbox\Outcomes\Outcome;
use App\Models\Conversation;
use App\Models\QueueEntry;
use App\Models\SupportCase;
use App\Queue\ConversationCycle;
use App\Queue\FinishRefused;
use App\Queue\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «خلصت» (close-cycle-FINAL §5.1), shared by the web inbox (`/conversations/{id}/finish`) and the
 * API (`/api/v1/conversations/{id}/finish`): the one end of a conversation, its «تراجع», the
 * outcome of a silently auto-closed window set later (Q6) and the list of those windows.
 * Uses `listRow()` from ConversationEndpoints.
 */
trait FinishEndpoints
{
    public function finish(Request $request, Conversation $conversation, ConversationCycle $cycle): JsonResponse
    {
        Gate::authorize('reply', $conversation);

        $data = $request->validate([
            'entry_id' => ['present', 'nullable', 'integer'],
            'outcome' => ['nullable', 'string', Rule::in(Outcome::agentValues())],
            'outcome_note' => ['nullable', 'required_if:outcome,other', 'string', 'max:120'],
            'after' => ['sometimes', 'string', Rule::in(['thanks', 'bot'])],
            'send_closing' => ['sometimes', 'boolean'],
            'confirm_waiting' => ['sometimes', 'boolean'],
            'open_case' => ['sometimes', 'nullable', 'string', Rule::in(SupportCase::TYPES)],
            'resolve_case_id' => ['sometimes', 'nullable', 'integer'],
        ], [
            'outcome.in' => __('errors.outcome.required'),
            'outcome_note.required_if' => __('errors.outcome.note_required'),
        ]);

        $r = [
            'entry_id' => $data['entry_id'],
            'outcome' => isset($data['outcome']) ? Outcome::from($data['outcome']) : null,
            'outcome_note' => $data['outcome_note'] ?? null,
            'after' => $data['after'] ?? 'thanks',
            'confirm_waiting' => (bool) ($data['confirm_waiting'] ?? false),
            'open_case' => $data['open_case'] ?? null,
            'resolve_case_id' => $data['resolve_case_id'] ?? null,
            'lounge_message' => true, 'closing_without_window' => true,
        ];
        if (array_key_exists('send_closing', $data)) {
            $r['send_closing'] = (bool) $data['send_closing'];
        }

        $result = $this->runCycle(fn () => $cycle->finish($conversation, $request->user(), $r));

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $entry = $result['entry'];

        return response()->json(['data' => [
            'conversation' => (new ConversationResource($this->listRow($conversation)))->resolve($request),
            'entry' => $entry ? QueueEntryResource::data($entry->load('conversation.customer')) + ['end_mode' => $entry->end_mode] : null,
            'path' => $result['path'],
            'end_mode' => $result['end_mode'],
            'closing' => $result['closing'],
            'skipped_reason' => $result['skipped_reason'],
            'support_case_id' => $result['support_case_id'],
            'undo_until' => $result['undo_until'],
        ]]);
    }

    public function undoFinish(Request $request, Conversation $conversation, ConversationCycle $cycle): JsonResponse
    {
        Gate::authorize('reply', $conversation);

        $entry = $this->runCycle(fn () => $cycle->undo($conversation, $request->user()));

        if ($entry instanceof JsonResponse) {
            return $entry;
        }

        return response()->json(['data' => [
            'conversation' => (new ConversationResource($this->listRow($conversation)))->resolve($request),
            'entry' => QueueEntryResource::data($entry->load('conversation.customer')),
        ]]);
    }

    /** Q6: the outcome of her silently auto-closed window, set later. */
    public function amendOutcome(Request $request, Conversation $conversation, ConversationCycle $cycle): JsonResponse
    {
        Gate::authorize('reply', $conversation);

        $data = $request->validate([
            'outcome' => ['required', 'string', Rule::in(Outcome::agentValues())],
            'outcome_note' => ['nullable', 'required_if:outcome,other', 'string', 'max:120'],
        ], [
            'outcome.required' => __('errors.outcome.required'),
            'outcome.in' => __('errors.outcome.required'),
            'outcome_note.required_if' => __('errors.outcome.note_required'),
        ]);

        $entry = $this->runCycle(fn () => $cycle->amendOutcome($conversation, $request->user(), Outcome::from($data['outcome']), $data['outcome_note'] ?? null));

        if ($entry instanceof JsonResponse) {
            return $entry;
        }

        return response()->json(['data' => ['entry_id' => $entry->id, 'outcome' => $data['outcome']]]);
    }

    /** Q6: her windows the silence closed today with no outcome yet (the strip's «حددي النتيجة» list). */
    public function autoClosed(Request $request, QueueService $queue): JsonResponse
    {
        $rows = QueueEntry::query()->with('conversation.customer')
            ->where('assigned_user_id', $request->user()->id)->where('status', 'closed')->where('close_reason', 'auto')
            ->where('closed_at', '>=', now()->setTimezone(QueueService::TZ)->startOfDay()->utc())
            ->whereExists(fn ($q) => $q->from('conversation_outcomes')->whereColumn('conversation_outcomes.queue_entry_id', 'queue_entries.id')
                ->where('conversation_outcomes.outcome', Outcome::Unknown->value))
            ->orderByDesc('closed_at')->limit(50)->get();

        return response()->json(['data' => $rows->map(fn (QueueEntry $e) => [
            'entry_id' => $e->id, 'conversation_id' => $e->conversation_id, 'ticket' => $e->ticket_no % 100000,
            'customer_name' => $e->conversation?->customer?->name, 'closed_at' => $e->closed_at?->toIso8601String(),
        ])->values()]);
    }

    /** A refusal as the API's answer: `outcome_required` stays a validation error on `outcome` (the app reads it there). */
    private function runCycle(callable $run): mixed
    {
        try {
            return $run();
        } catch (FinishRefused $x) {
            if ($x->key === 'outcome_required') {
                throw ValidationException::withMessages(['outcome' => __('errors.outcome.required')]);
            }

            return response()->json(['message' => __('errors.queue.'.$x->key), 'code' => $x->key] + $x->extra, $x->status);
        }
    }
}
