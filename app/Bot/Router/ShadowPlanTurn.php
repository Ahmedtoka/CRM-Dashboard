<?php

namespace App\Bot\Router;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Shadow mode (bot-hybrid.md §6.1, `crm.bot.router.mode = shadow`): after the live bot answered a
 * turn the old way, the planner reads the same messages on a queue and records what the hybrid
 * router would have done — the tasks, the tier and why — in `bot_shadow_plans`.
 * Nothing is sent and nothing in the chat changes. Run it 3–5 days before switching to hybrid.
 */
class ShadowPlanTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    /**
     * @param  list<int>  $messageIds  the turn's customer messages
     * @param  array{flow:?string, step:?string, last_question:?string}|null  $state  where the chat was BEFORE the live reply
     */
    public function __construct(public readonly int $conversationId, public readonly array $messageIds, public readonly ?array $state = null)
    {
        $this->onQueue((string) config('crm.bot.router.shadow_queue', 'outbound'));
    }

    /**
     * Queues the shadow plan of a turn (never on a sync queue: it would slow the live reply).
     *
     * @param  array{flow:?string, step:?string, last_question:?string}  $state  taken before the live reply
     */
    public static function for(Conversation $c, Collection $burst, array $state): void
    {
        $ids = $burst->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all();

        if ($ids === [] || Queue::connection() instanceof SyncQueue) {
            return;
        }

        rescue(fn () => self::dispatch($c->id, $ids, $state), report: false);
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
            [$plan, $cost, $model] = $router->plan($c, $burst, $texts, $this->state);
            $decision = app(EscalationPolicy::class)->decide($plan, $c, $text);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        // Its own table: a shadow plan is not a bot turn (turn limit, metrics, learning — review #1).
        DB::table('bot_shadow_plans')->insert([
            'conversation_id' => $c->id,
            'trigger_message' => $text,
            'model' => $model,
            'tier' => $decision['tier'],
            'reason' => $decision['reason'] !== null ? mb_substr($decision['reason'], 0, 40) : null,
            'kinds' => mb_substr(implode(',', $plan->kinds()), 0, 190),
            'directed' => min(255, count($plan->directed())),
            'confidence' => round($plan->confidence(), 2),
            'plan' => json_encode($plan->toArray(), JSON_UNESCAPED_UNICODE),
            'cost_usd' => round($cost, 6),
            'planner_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
