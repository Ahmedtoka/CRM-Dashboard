<?php

namespace App\Http\Controllers\Web\Bot;

use App\Bot\Brain\Trace\TraceView;
use App\Enums\SenderType;
use App\Http\Controllers\Controller;
use App\Models\BotReplyFeedback;
use App\Models\BotRun;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The two actions on a bot bubble in the inbox (b2, 2026-10-19), for everyone who can open the
 * chat: «ليه البوت رد كده؟» (the turn's trace) and «رد البوت ده غلط» (feedback).
 */
class BotReplyInsightController extends Controller
{
    public function trace(Request $request, Message $message, TraceView $view): JsonResponse
    {
        $this->authorizeBotMessage($message);
        $id = $view->traceIdFor($message);

        return response()->json([
            'data' => $id !== null ? $view->show($id) : null,
            'feedback' => $this->mine($request, $message),
        ]);
    }

    public function feedback(Request $request, Message $message, TraceView $view): JsonResponse
    {
        $this->authorizeBotMessage($message);

        $data = $request->validate([
            'reasons' => ['required', 'array', 'min:1', 'max:8'],
            'reasons.*' => ['string', Rule::in(BotReplyFeedback::REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
            'correct_reply' => ['nullable', 'string', 'max:2000'],
        ]);

        $traceId = $view->traceIdFor($message);
        $runId = $traceId !== null ? DB::table('bot_reply_traces')->where('id', $traceId)->value('bot_run_id') : null;
        $run = $runId !== null ? BotRun::query()->find($runId) : null;

        $feedback = BotReplyFeedback::query()->updateOrCreate(
            ['message_id' => $message->id, 'user_id' => $request->user()->id],
            [
                'conversation_id' => $message->conversation_id,
                'bot_run_id' => $run?->id,
                'reasons' => array_values(array_unique($data['reasons'])),
                'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                'correct_reply' => filled($data['correct_reply'] ?? null) ? trim($data['correct_reply']) : null,
                'tier' => $run?->tier,
                'layer' => $run !== null ? TraceView::layer($run) : null,
            ],
        );

        return response()->json(['data' => $this->present($feedback)], $feedback->wasRecentlyCreated ? 201 : 200);
    }

    private function authorizeBotMessage(Message $message): void
    {
        abort_unless($message->sender_type === SenderType::Bot && $message->conversation !== null, 404);
        Gate::authorize('view', $message->conversation);
    }

    /** @return array<string, mixed>|null */
    private function mine(Request $request, Message $message): ?array
    {
        $row = BotReplyFeedback::query()->where('message_id', $message->id)->where('user_id', $request->user()->id)->first();

        return $row !== null ? $this->present($row) : null;
    }

    /** @return array<string, mixed> */
    private function present(BotReplyFeedback $f): array
    {
        return ['id' => $f->id, 'reasons' => $f->reasons, 'note' => $f->note, 'correct_reply' => $f->correct_reply, 'status' => $f->status];
    }
}
