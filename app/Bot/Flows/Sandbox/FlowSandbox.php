<?php

namespace App\Bot\Flows\Sandbox;

use App\Bot\Flows\FlowDefinitionSource;
use App\Bot\Flows\FlowDrafts;
use App\Bot\Flows\FlowEngine;
use App\Bot\Flows\FlowHandover;
use App\Bot\Flows\FlowResult;
use App\Bot\Flows\FlowState;
use App\Bot\Flows\HumanHandover;
use App\Bot\Flows\PublishedFlowDefinitions;
use App\Cases\CaseRecorder;
use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\OutboundService;
use App\Models\BotFlow;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;

/**
 * Runs one simulated customer turn of a guided flow for the flow designer
 * (design doc 2026-09-17-flow-designer §3). Everything happens inside a
 * transaction that is always rolled back: a throwaway customer, channel
 * account, conversation and inbound message are created, the real FlowEngine
 * runs with sandbox collaborators (no sends, cases, handovers or broadcasts),
 * and the resulting messages, events and flow state are returned. Order
 * lookups read the real orders inside that transaction.
 */
final class FlowSandbox
{
    public const SOURCES = ['draft', 'published'];

    public function __construct(
        private readonly Container $app,
        private readonly FlowDrafts $drafts,
        private readonly PublishedFlowDefinitions $published,
    ) {}

    /**
     * @param  'draft'|'published'  $source
     * @param  array{flow?:array, flow_confirm?:string}|null  $state  the `bot_state` subset returned by a previous run; null starts the flow under test
     * @param  array{text?:?string, payload?:?string, photo?:bool}  $input
     * @return array{messages: list<array{text:string, buttons: list<array{title:string, payload:string}>}>, events: list<array{type:string, label:string, data:array}>, state: array|null, current: array{flow_key:string, step_id:string}|null}
     */
    public function run(BotFlow $flow, string $source, ?array $state, array $input, User $by): array
    {
        $log = new SandboxLog;
        $previous = [];
        $wasActive = SandboxMode::active();
        $inTransaction = false;

        try {
            $this->swap($previous, [
                OutboundService::class => new SandboxOutbound($log),
                CaseRecorder::class => new SandboxCaseRecorder($log),
                FlowHandover::class => new SandboxHandover($log),
                FlowDefinitionSource::class => new SandboxDefinitions($flow, $source, $this->drafts, $this->published),
            ]);
            SandboxMode::set(true);
            DB::beginTransaction();
            $inTransaction = true;

            $state = $this->stateSubset($state);
            $c = $this->conversation($state);
            $previousKey = FlowState::flow($c)['key'] ?? null;
            $engine = $this->app->make(FlowEngine::class);
            $result = null;

            // A pending «محتاجة إيه؟» (flow 7) is answered like a flow step.
            if ($previousKey === null && ! HumanHandover::pending($c)) {
                $engine->start($c, $flow->key);
            } else {
                $result = $engine->handle($c, collect([$this->inbound($c, $input)]));
            }

            $after = FlowState::flow($c);
            $resultState = $this->stateSubset($c->bot_state);
        } finally {
            if ($inTransaction) {
                // A failed rollback must not skip resetting the mode and the bindings.
                rescue(fn () => DB::rollBack(), null, report: true);
            }

            SandboxMode::set($wasActive);
            $this->restore($previous);
        }

        $this->navigationEvent($log, $previousKey, $after['key'] ?? null);
        $this->emptyTurnEvent($log, $result, $after);

        return [
            'messages' => $log->messages,
            'events' => $log->events,
            'state' => $resultState,
            'current' => $after !== null ? ['flow_key' => $after['key'], 'step_id' => $after['step']] : null,
        ];
    }

    /**
     * A turn that says nothing gets an informational event so the designer knows why:
     * a question (live, the agent answers and the step is asked again), an exit, or a
     * flow under test that cannot start (invalid definition).
     */
    private function emptyTurnEvent(SandboxLog $log, ?FlowResult $result, ?array $after): void
    {
        if ($log->messages !== [] || $log->events !== []) {
            return;
        }

        if ($result === null) {
            if ($after === null) {
                $log->event('invalid', 'الفلو ده فيه أخطاء ومش هيشتغل');
            }

            return;
        }

        if ($result->question !== null) {
            $log->event('question', 'السؤال ده هيرد عليه الـ Agent في الحقيقة، وبعدين يرجع يسأل نفس الخطوة', ['text' => $result->question]);
        } elseif ($result->exited) {
            $log->event('exit', 'رجوع للقائمة');
        }
    }

    /** Only `flow`, `flow_confirm` and a pending handover topic travel in and out; null when none is set. */
    private function stateSubset(?array $state): ?array
    {
        $subset = array_filter(
            [
                'flow' => $state['flow'] ?? null,
                'flow_confirm' => $state['flow_confirm'] ?? null,
                HumanHandover::STATE_KEY => $state[HumanHandover::STATE_KEY] ?? null,
            ],
            fn ($v) => $v !== null && $v !== '' && $v !== [],
        );

        return $subset !== [] ? $subset : null;
    }

    private function conversation(?array $state): Conversation
    {
        $customer = Customer::create(['name' => 'تجربة الفلو']);
        $account = ChannelAccount::create([
            'platform' => Platform::Facebook,
            'name' => 'Flow sandbox',
            'external_id' => 'flow-sandbox-'.bin2hex(random_bytes(6)),
            'driver' => 'fake',
            'status' => 'connected',
        ]);

        return Conversation::create([
            'customer_id' => $customer->id,
            'channel_account_id' => $account->id,
            'platform' => Platform::Facebook,
            'status' => ConversationStatus::Open,
            'handler' => Handler::Bot,
            'needs_human' => false,
            'source' => ConversationSource::Direct,
            'unread_count' => 0,
            'bot_state' => $state,
        ]);
    }

    private function inbound(Conversation $c, array $input): Message
    {
        $photo = (bool) ($input['photo'] ?? false);

        return $c->messages()->create([
            'platform' => $c->platform,
            'direction' => MessageDirection::In,
            'sender_type' => SenderType::Customer,
            'body' => $photo ? null : (string) ($input['text'] ?? ''),
            'payload' => filled($input['payload'] ?? null) ? (string) $input['payload'] : null,
            'attachments' => $photo ? [['type' => 'image']] : null,
            'status' => MessageStatus::Received,
            'is_template' => false,
        ]);
    }

    /** Moving to another flow: back to the main menu is an `exit`, anything else a `flow_start`. */
    private function navigationEvent(SandboxLog $log, ?string $from, ?string $to): void
    {
        if ($from === null || $to === null || $from === $to) {
            return;
        }

        if ($to === 'main_menu') {
            $log->event('exit', 'رجوع للقائمة', ['flow_key' => $to]);

            return;
        }

        $title = BotFlow::query()->where('key', $to)->value('title_ar') ?? $to;
        $log->event('flow_start', 'بدء فلو: '.$title, ['flow_key' => $to]);
    }

    /**
     * Fills $previous as it goes, so a failure part-way still restores what was swapped.
     *
     * @param  array<class-string, object|null>  $previous  the instances replaced (null = none was set)
     * @param  array<class-string, object>  $instances
     */
    private function swap(array &$previous, array $instances): void
    {
        foreach ($instances as $abstract => $instance) {
            $previous[$abstract] = $this->app->isShared($abstract) && $this->app->resolved($abstract) ? $this->app->make($abstract) : null;
            $this->app->instance($abstract, $instance);
        }
    }

    /** @param  array<class-string, object|null>  $previous */
    private function restore(array $previous): void
    {
        foreach ($previous as $abstract => $instance) {
            if ($instance !== null) {
                $this->app->instance($abstract, $instance);
            } else {
                $this->app->forgetInstance($abstract);
            }
        }
    }
}
