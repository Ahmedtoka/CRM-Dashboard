<?php

namespace App\Bot\Router;

use App\Models\BotRun;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Shadow mode (bot-hybrid.md §6.1, `crm.bot.router.mode = shadow`): after the live bot answered a
 * turn the old way, the planner reads the same messages on a queue and records what the hybrid
 * router would have done — the tasks, the tier and why — as a bot run with engine `shadow`.
 * Nothing is sent and nothing in the chat changes. Run it 3–5 days before switching to hybrid.
 */
class ShadowPlanTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    /** @param  list<int>  $messageIds  the turn's customer messages */
    public function __construct(public readonly int $conversationId, public readonly array $messageIds)
    {
        $this->onQueue((string) config('crm.bot.router.shadow_queue', 'outbound'));
    }

    /** Queues the shadow plan of a turn (never on a sync queue: it would slow the live reply). */
    public static function for(Conversation $c, Collection $burst): void
    {
        $ids = $burst->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all();

        if ($ids === [] || Queue::connection() instanceof SyncQueue) {
            return;
        }

        rescue(fn () => self::dispatch($c->id, $ids), report: false);
    }

    public function handle(): void
    {
        $c = Conversation::find($this->conversationId);
        $burst = Message::whereIn('id', $this->messageIds)->orderBy('id')->get();
        $texts = $burst->pluck('body')->map(fn ($b) => trim((string) $b))->filter()->values()->all();

        if ($c === null || $texts === []) {
            return;
        }

        $router = app(HybridRouter::class);
        $text = implode("\n", $texts);
        $started = hrtime(true);

        try {
            [$plan, $cost, $model] = $router->plan($c, $burst, $texts);
            $decision = app(EscalationPolicy::class)->decide($plan, $c, $text);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        BotRun::create([
            'conversation_id' => $c->id,
            'trigger_message' => $text,
            'engine' => 'shadow',
            'model' => $model,
            'tier' => $decision['tier'],
            'intent' => mb_substr(implode(',', $plan->kinds()).($decision['reason'] !== null ? '|'.$decision['reason'] : ''), 0, 190),
            'confidence' => round($plan->confidence(), 2),
            'decision' => 'shadow',
            'reply_text' => json_encode($plan->toArray() + ['directed' => count($plan->directed())], JSON_UNESCAPED_UNICODE),
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd' => round($cost, 6),
            'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'planner_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);
    }
}
