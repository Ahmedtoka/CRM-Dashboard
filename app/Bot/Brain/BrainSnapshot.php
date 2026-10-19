<?php

namespace App\Bot\Brain;

use App\Bot\Agent\AgentTools;
use App\Bot\Agent\StoreAgent;
use App\Bot\Ai\SonnetBudget;
use App\Bot\Brain\Trace\TraceView;
use App\Bot\Router\HybridRouter;
use App\Bot\WorkingHours;
use App\Models\BotKnowledgeEntry;
use App\Models\BotReplyFeedback;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\BotSuggestion;
use App\Queue\QueueService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * «عقل البوت» (b2, 2026-10-19): what the bot runs on right now, read live — the router and the
 * models, the exact system prompt the agent is sent (and where each part is edited), its sources
 * of information, its tools, when it hands over and to whom, and the guards on its replies.
 * `summary()` is the moderators' read-only version (no prompt, no settings).
 */
class BrainSnapshot
{
    /** @return array<string, mixed> */
    public function build(): array
    {
        return $this->summary() + [
            'prompt' => $this->prompt(),
            'sources' => $this->sources(),
            'pending' => [
                'suggestions' => BotSuggestion::query()->where('status', 'pending')->count(),
                'feedback' => BotReplyFeedback::query()->where('status', 'open')->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $s = BotSetting::current();

        return [
            'state' => [
                'bot_enabled' => (bool) $s->enabled,
                'ai_enabled' => (bool) $s->ai_enabled,
                'agent_ready' => StoreAgent::enabled(),
            ],
            'router' => $this->router(),
            'tools' => $this->tools(),
            'handover' => $this->handover($s),
            'guards' => ['price', 'promise', 'link', 'order_fact'],
            'layers_7d' => $this->layers(),
        ];
    }

    /** @return array<string, mixed> */
    private function router(): array
    {
        $budget = app(SonnetBudget::class);

        return [
            'mode' => HybridRouter::mode(),
            'legacy_fallback' => (bool) config('crm.bot.router.legacy_fallback', false),
            'models' => [
                'planner' => (string) config('crm.bot.router.planner.model'),
                'tier1' => (string) config('crm.bot.router.tiers.1.model'),
                'tier2' => (string) config('crm.bot.router.tiers.2.model'),
                'legacy' => (string) config('crm.bot.agent.model'),
            ],
            'effort' => [
                'tier1' => (string) config('crm.bot.router.tiers.1.effort'),
                'tier2' => (string) config('crm.bot.router.tiers.2.effort'),
                'legacy' => (string) config('crm.bot.agent.effort'),
            ],
            'sonnet_cap' => ['cap' => (float) config('crm.bot.router.daily_sonnet_cap_usd', 5), 'spent_today' => round($budget->spentToday(), 4), 'hit' => $budget->exceeded()],
            'max_tasks' => (int) config('crm.bot.router.max_tasks', 4),
        ];
    }

    /**
     * The exact system text, then its parts: the fixed rules (in code), the owner's instructions
     * and the knowledge entries (editable in the CRM).
     *
     * @return array<string, mixed>
     */
    private function prompt(): array
    {
        $instructions = BotKnowledgeEntry::query()->where('key', StoreAgent::INSTRUCTIONS_KEY)->first(['id', 'body', 'updated_at']);
        $knowledge = BotKnowledgeEntry::query()->where('is_active', true)->where('key', 'not like', 'script.%')->where('key', '!=', StoreAgent::INSTRUCTIONS_KEY);

        try {
            $text = app(StoreAgent::class)->systemText();
        } catch (Throwable $e) {
            report($e);
            $text = '';
        }

        return [
            'text' => $text,
            'chars' => mb_strlen($text),
            // A rough token count (~3.5 characters per token for this mix of Arabic and English).
            'approx_tokens' => (int) round(mb_strlen($text) / 3.5),
            'parts' => [
                ['key' => 'rules', 'editable' => false, 'chars' => mb_strlen(StoreAgent::SYSTEM_PROMPT)],
                ['key' => 'instructions', 'editable' => true, 'href' => '/bot/replies', 'chars' => mb_strlen((string) $instructions?->body), 'updated_at' => $instructions?->updated_at?->toIso8601String()],
                ['key' => 'knowledge', 'editable' => true, 'href' => '/bot/knowledge', 'count' => (clone $knowledge)->count(), 'updated_at' => (clone $knowledge)->max('updated_at')],
            ],
            'context_lines' => ['channel', 'reply_language', 'inside_flow', 'plan'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function sources(): array
    {
        return array_values(array_filter([
            $this->source('knowledge', 'bot_knowledge_entries', fn ($q) => $q->where('is_active', true), '/bot/knowledge'),
            $this->source('catalog', 'products', fn ($q) => $q->where('status', 'active'), '/products'),
            $this->source('variants', 'product_variants', null, null),
            $this->source('orders', 'orders', null, '/orders'),
            $this->source('branches', 'branches', fn ($q) => $q->where('is_active', true), '/settings/branch-hours'),
            $this->source('shipping', 'shipping_rates', null, null),
        ]));
    }

    /** @return array<string, mixed>|null */
    private function source(string $key, string $table, ?callable $scope, ?string $href): ?array
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $q = DB::table($table);

        if ($scope !== null) {
            $scope($q);
        }

        return ['key' => $key, 'count' => (clone $q)->count(), 'updated_at' => Schema::hasColumn($table, 'updated_at') ? (clone $q)->max('updated_at') : null, 'href' => $href];
    }

    /** @return list<array{name: string, description: string}> */
    private function tools(): array
    {
        return collect(app(AgentTools::class)->definitions(false, true))
            ->map(fn (array $t) => ['name' => (string) $t['name'], 'description' => (string) ($t['description'] ?? '')])
            ->values()->all();
    }

    /** @return array<string, mixed> */
    private function handover(BotSetting $s): array
    {
        $queue = app(QueueService::class);

        return [
            'keywords' => array_values((array) $s->handover_keywords),
            'max_bot_turns' => (int) $s->max_bot_turns,
            'max_tasks' => (int) config('crm.bot.router.max_tasks', 4),
            'reasons' => ['keyword', 'human_request', 'max_turns', 'too_many_tasks', 'angry_threat', 'escalated', 'no_script', 'window_closed'],
            'escalations' => ['E1', 'E2', 'E3', 'E4', 'E6'],
            'queue_on' => $queue->shouldQueue(),
            'hours_configured' => WorkingHours::configured($s),
        ];
    }

    /** Who answered in the last 7 days, by layer. @return array<string, int> */
    private function layers(): array
    {
        $out = array_fill_keys(['script', 'rule', 'flow', 'agent', 'fallback', 'handover'], 0);

        DB::table('bot_runs')->where('created_at', '>=', now()->subDays(7))->whereNotNull('conversation_id')
            ->groupBy('engine', 'tier', 'decision')->selectRaw('engine, tier, decision, count(*) as n')->get()
            ->each(function ($row) use (&$out) {
                $layer = TraceView::layer(new BotRun(['engine' => $row->engine, 'tier' => $row->tier, 'decision' => $row->decision]));
                $out[$layer] += (int) $row->n;
            });

        return $out;
    }
}
