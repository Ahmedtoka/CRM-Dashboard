<?php

namespace App\Bot\Brain\Console;

use App\Analytics\ActivityLogger;
use App\Bot\Agent\StoreAgent;
use App\Bot\BotEngine;
use App\Bot\Brain\Costs\BotCostReport;
use App\Bot\Brain\Trace\TraceView;
use App\Bot\Brain\Trace\TurnTrace;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\Sandbox\SandboxLog;
use App\Bot\Flows\Sandbox\SandboxMode;
use App\Bot\Router\TurnMetrics;
use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Events\CommentUpdated;
use App\Events\ConversationUpdated;
use App\Events\CustomerUpdated;
use App\Events\MessageCreated;
use App\Events\MessageUpdated;
use App\Events\OrderUpdated;
use App\Events\UserNotified;
use App\Inbox\OutboundService;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Queue\Events\QueueAssigned;
use App\Queue\Events\QueueEntryUpdated;
use App\Queue\Events\QueueMemberUpdated;
use App\Queue\Events\RouterDecided;
use App\Queue\Events\ShiftUpdated;
use App\Queue\QueueScripts;
use App\Queue\QueueService;
use App\Queue\WaitEstimator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * «جرّبي البوت» (b2, 2026-10-19): one customer message through the real bot (BotEngine::handleTurn,
 * the same router, models, flows and guards as live) in a throwaway chat. Everything happens inside a
 * transaction that is always rolled back; nothing reaches a channel (SandboxOutbound), nothing is
 * queued and nothing is broadcast to the team. The model calls are real (they cost money, counted
 * in «التكلفة» as the console's spend). The chat carries on across turns: the caller sends back the
 * earlier lines and the bot state this returns.
 */
class BotConsole
{
    private const HISTORY_MAX = 20;

    /** Everything real-time the team's screens listen to: faked for the console's throwaway chat. */
    private const BROADCASTS = [
        ConversationUpdated::class, MessageCreated::class, MessageUpdated::class, OrderUpdated::class, CustomerUpdated::class, UserNotified::class, CommentUpdated::class,
        QueueAssigned::class, QueueEntryUpdated::class, QueueMemberUpdated::class, RouterDecided::class, ShiftUpdated::class,
    ];

    /**
     * @param  list<array{role: 'customer'|'bot', text: string}>  $history  the console's earlier lines
     * @param  array<string, mixed>|null  $state  the bot_state a previous run returned
     * @return array<string, mixed>
     */
    public function run(string $text, array $history = [], ?array $state = null): array
    {
        $log = new SandboxLog;
        $previousOutbound = app(OutboundService::class);
        $previousQueue = app(QueueService::class);
        $previousCache = Cache::getDefaultDriver();
        $wasSandbox = SandboxMode::active();
        $result = ['replies' => [], 'trace' => null, 'handover' => false, 'flow' => null, 'state' => null, 'error' => null];

        Queue::fake();
        Event::fake(self::BROADCASTS);
        app()->instance(OutboundService::class, new ConsoleOutbound($log));
        // A handover is shown, never routed: no ticket, no queue_days row lock, no router lock.
        app()->instance(QueueService::class, new class(app(ActivityLogger::class), app(WaitEstimator::class), app(QueueScripts::class)) extends QueueService
        {
            public function shouldQueue(): bool
            {
                return false;
            }
        });
        // The turn's cache writes (the Sonnet spend counter, locks) stay in memory: with the database
        // cache store they would hold row locks against live bot turns until the rollback.
        Cache::setDefaultDriver('array');
        SandboxMode::set(true);
        DB::beginTransaction();

        try {
            $c = $this->conversation($state);
            $this->history($c, $history);
            $message = $c->messages()->create([
                'platform' => $c->platform, 'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer,
                'body' => $text, 'status' => MessageStatus::Received, 'is_template' => false, 'is_test' => true,
            ]);

            $metrics = app(TurnMetrics::class);
            $metrics->reset();
            $metrics->startedAtMs = (int) floor(microtime(true) * 1000);
            $trace = app(TurnTrace::class);
            $trace->reset();
            $trace->lastPayload = null;
            $c->forceFill(['bot_state' => array_merge($c->bot_state ?? [], ['last_turn_message_id' => $message->id])])->save();
            $runsBefore = (int) (BotRun::max('id') ?? 0);

            app(BotEngine::class)->handleTurn($c->refresh(), collect([$message]));

            $c->refresh();
            $run = BotRun::query()->where('id', '>', $runsBefore)->latest('id')->first();
            $spent = (float) BotRun::query()->where('id', '>', $runsBefore)->sum('cost_usd');

            $result = [
                'replies' => array_map(fn (array $m) => ['text' => $m['text'] ?? '', 'buttons' => array_values(array_map(fn ($b) => is_array($b) ? (string) ($b['title'] ?? '') : (string) $b, (array) ($m['buttons'] ?? []))), 'cards' => count((array) ($m['cards']['cards'] ?? $m['cards'] ?? []))], $log->messages),
                'trace' => $run !== null ? TraceView::compose($run, $trace->lastPayload ?? []) : null,
                'handover' => $c->handler !== Handler::Bot,
                'flow' => FlowState::flow($c)['key'] ?? null,
                'state' => $c->handler === Handler::Bot ? $c->bot_state : null,
                'spent_usd' => round($spent, 6),
                'error' => null,
            ];
        } catch (Throwable $e) {
            report($e);
            $result['error'] = $e->getMessage();
        } finally {
            rescue(fn () => DB::rollBack(), null, report: true);
            SandboxMode::set($wasSandbox);
            Cache::setDefaultDriver($previousCache);
            app()->instance(OutboundService::class, $previousOutbound);
            app()->instance(QueueService::class, $previousQueue);
        }

        if (($result['spent_usd'] ?? 0) > 0) {
            BotCostReport::addConsoleSpend((float) $result['spent_usd']);
        }

        $result['agent_ready'] = StoreAgent::enabled();
        $result['ai_enabled'] = (bool) BotSetting::current()->ai_enabled;

        return $result;
    }

    /** @param  array<string, mixed>|null  $state */
    private function conversation(?array $state): Conversation
    {
        $account = ChannelAccount::create([
            'platform' => Platform::Facebook, 'name' => 'جرّبي البوت', 'external_id' => 'bot-console-'.bin2hex(random_bytes(6)), 'driver' => 'fake', 'status' => 'connected',
        ]);
        $customer = Customer::create(['name' => 'تجربة البوت']);

        return Conversation::create([
            'customer_id' => $customer->id, 'channel_account_id' => $account->id, 'platform' => Platform::Facebook,
            'status' => ConversationStatus::Open, 'handler' => Handler::Bot, 'needs_human' => false, 'source' => ConversationSource::Direct,
            'unread_count' => 0, 'language' => 'ar', 'last_customer_message_at' => now(), 'is_test' => true,
            'bot_state' => $state !== null ? array_diff_key($state, ['last_turn_message_id' => true]) : null,
        ]);
    }

    /** @param  list<array{role: string, text: string}>  $history */
    private function history(Conversation $c, array $history): void
    {
        foreach (array_slice($history, -self::HISTORY_MAX) as $line) {
            $text = trim((string) ($line['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $bot = ($line['role'] ?? '') === 'bot';
            $c->messages()->create([
                'platform' => $c->platform, 'direction' => $bot ? MessageDirection::Out : MessageDirection::In,
                'sender_type' => $bot ? SenderType::Bot : SenderType::Customer, 'body' => $text,
                'status' => $bot ? MessageStatus::Sent : MessageStatus::Received, 'is_template' => false, 'is_test' => true,
            ]);
        }
    }
}
