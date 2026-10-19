<?php

namespace App\Http\Controllers\Web\Bot;

use App\Bot\Brain\Feedback\FeedbackConverter;
use App\Bot\Brain\Trace\TraceView;
use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Models\BotReplyFeedback;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * «مراجعة ردود البوت» (b2, 2026-10-19): the staff's «رد البوت ده غلط» items, filtered by reason,
 * layer/tier and date, each linked to its chat and its trace; one click turns an item into a
 * pending learning suggestion (approved in «التعلم») or dismisses it.
 */
class BotFeedbackController extends Controller
{
    use RespondsWithData;

    private const LAYERS = ['script', 'rule', 'flow', 'agent', 'fallback', 'handover'];

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'reason' => ['nullable', Rule::in(BotReplyFeedback::REASONS)],
            'layer' => ['nullable', Rule::in(self::LAYERS)],
            'tier' => ['nullable', Rule::in(['0', '1', '2', 'legacy', 'flow'])],
            'status' => ['nullable', Rule::in(BotReplyFeedback::STATUSES)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $status = $filters['status'] ?? 'open';
        $tz = 'Africa/Cairo';

        $query = BotReplyFeedback::query()
            ->with(['message:id,body,created_at', 'user:id,name', 'botRun:id,trigger_message,engine,tier,decision,model,cost_usd'])
            ->where('status', $status)
            ->when($filters['reason'] ?? null, fn ($q, $r) => $q->whereJsonContains('reasons', $r))
            ->when($filters['layer'] ?? null, fn ($q, $l) => $q->where('layer', $l))
            ->when($filters['tier'] ?? null, fn ($q, $t) => $q->where('tier', $t))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', CarbonImmutable::parse($d, $tz)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', CarbonImmutable::parse($d, $tz)->endOfDay()->utc()))
            ->latest('id');

        $items = $query->paginate(30)->withQueryString();
        $traceIds = DB::table('bot_reply_traces')->whereIn('bot_run_id', collect($items->items())->pluck('bot_run_id')->filter())->pluck('id', 'bot_run_id');

        return Inertia::render('Bot/Feedback', [
            'filters' => $filters + ['status' => $status],
            'items' => $items->through(fn (BotReplyFeedback $f) => [
                'id' => $f->id,
                'reasons' => $f->reasons,
                'note' => $f->note,
                'correct_reply' => $f->correct_reply,
                'status' => $f->status,
                'converted_to' => $f->converted_to,
                'suggestion_id' => $f->suggestion_id,
                'layer' => $f->layer,
                'tier' => $f->tier,
                'by' => $f->user?->name,
                'at' => $f->created_at?->toIso8601String(),
                'reply' => $f->message?->body,
                'trigger' => $f->botRun?->trigger_message,
                'model' => $f->botRun?->model,
                'conversation_id' => $f->conversation_id,
                'message_id' => $f->message_id,
                'trace_id' => $f->bot_run_id !== null ? ($traceIds[$f->bot_run_id] ?? null) : null,
            ]),
            'counts' => self::counts(),
            'reasons' => BotReplyFeedback::REASONS,
            'layers' => self::LAYERS,
        ]);
    }

    public function trace(int $trace, TraceView $view): HttpResponse
    {
        $data = $view->show($trace);
        abort_if($data === null, 404);

        return response()->json(['data' => $data]);
    }

    public function convert(Request $request, BotReplyFeedback $feedback, FeedbackConverter $converter): HttpResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(BotReplyFeedback::CONVERSIONS)],
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:4000'],
            'keywords' => ['nullable', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:60'],
            'line' => ['nullable', 'string', 'max:400'],
            'messages' => ['nullable', 'array', 'max:5'],
            'messages.*' => ['string', 'max:1000'],
            'expect' => ['nullable', 'array'],
        ]);

        try {
            $suggestion = $converter->convert($feedback, $data['kind'], $data, $request->user());
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['kind' => [$e->getMessage()]]], 422);
        }

        return $this->done($request, ['suggestion_id' => $suggestion->id, 'report_id' => $suggestion->report_id]);
    }

    public function dismiss(Request $request, BotReplyFeedback $feedback, FeedbackConverter $converter): HttpResponse
    {
        $converter->dismiss($feedback, $request->user());

        return $this->done($request, ['status' => 'dismissed']);
    }

    /**
     * Per reason, open items and the last 30 days (also on «أداء البوت»).
     *
     * @return array{open: int, last_30: int, by_reason: array<string, int>}
     */
    public static function counts(): array
    {
        $rows = BotReplyFeedback::query()->where('created_at', '>=', now()->subDays(30))->get(['reasons', 'status']);
        $byReason = array_fill_keys(BotReplyFeedback::REASONS, 0);

        foreach ($rows as $row) {
            foreach ((array) $row->reasons as $r) {
                if (array_key_exists($r, $byReason)) {
                    $byReason[$r]++;
                }
            }
        }

        return [
            'open' => BotReplyFeedback::query()->where('status', 'open')->count(),
            'last_30' => $rows->count(),
            'by_reason' => $byReason,
        ];
    }
}
