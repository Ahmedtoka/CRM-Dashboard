<?php

namespace App\Simulator\LoadTest;

use App\Bot\BotEngine;
use App\Enums\ConversationStatus;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Inbox\CustomerResolver;
use App\Inbox\InboxIngestor;
use App\Models\Conversation;
use App\Models\LoadTestRun;
use App\Models\QueueEntry;
use App\Models\Shift;
use App\Queue\QueueRouter;
use App\Simulator\Simulator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The production load test (2026-10-07), driven by `crm:load-test`. Every customer line goes
 * through the real webhook pipeline (a WebhookEvent + ProcessWebhookEvent) on a «تيست» channel.
 * Callers check the `crm.load_test` gate first.
 */
class LoadTest
{
    public const TZ = 'Africa/Cairo';

    /** Yesterday's backlog is spread over this evening slot (Cairo) so the 24 h reply window is still open this morning. */
    private const BACKLOG_FROM = '18:00';

    private const BACKLOG_SECONDS = 5 * 3600 + 30 * 60; // 18:00 → 23:30

    public function __construct(private readonly Simulator $simulator) {}

    /** Any shift still open: the backlog must not be written then (its clock runs yesterday). */
    public static function shiftIsOpen(): bool
    {
        return Shift::query()->where('status', 'open')->exists();
    }

    public static function enabled(): bool
    {
        return (bool) config('crm.load_test', false);
    }

    /**
     * N chats that wrote yesterday evening and were handed over: they wait in the queue (an
     * overnight ticket when nobody was on shift) for the agents who check in this morning.
     *
     * Real path, backdated: the clock is set to the customer's moment while her opener goes
     * through the webhook pipeline and the bot's handover (`BotEngine::handover()`: the summary
     * note, the system line, the real enqueue and ticket of that business day), so every row it
     * writes carries yesterday's time; it is given back afterwards. The bot never answers a
     * backlog chat: its conversation is opened as a person's before the opener lands.
     */
    public function seedYesterday(int $count): int
    {
        if (self::shiftIsOpen()) {
            throw new \RuntimeException('A shift is open: seed the backlog before the team checks in (close the shift first).');
        }

        $run = LoadTestRun::activeOrStart();
        LoadTestChannels::ensureAll();

        $from = CarbonImmutable::now(self::TZ)->subDay()->setTimeFromTimeString(self::BACKLOG_FROM);
        $moments = collect(range(1, max(0, $count)))
            ->map(fn () => $from->addSeconds(random_int(0, self::BACKLOG_SECONDS)))
            ->sort()->values();

        $previous = Carbon::getTestNow();
        $seeded = 0;
        // No router pass under the fake clock: the backlog is only enqueued (review round 1).
        app()->instance(QueueRouter::class, app(BacklogRouter::class));

        try {
            foreach ($moments as $at) {
                $this->seedOne($run, $at);
                $seeded++;
            }
        } finally {
            Carbon::setTestNow($previous);
            app()->forgetInstance(QueueRouter::class);
        }

        return $seeded;
    }

    private function seedOne(LoadTestRun $run, CarbonImmutable $at): void
    {
        $key = Scenarios::randomKey();
        $scenario = Scenarios::get($key);
        $platform = Scenarios::randomPlatform();
        $name = Scenarios::randomName();
        $customerKey = $this->customerKey($run, 'y');

        Carbon::setTestNow($at->setTimezone(config('app.timezone')));
        $this->openAsHuman($platform, $customerKey, $name);

        $tag = $this->tag($run, $key, $name, $customerKey);
        $opener = Scenarios::render($scenario['opener'], $tag['order_number']);

        $message = $this->simulator->customerMessage(
            $platform, $customerKey, $name, $opener, $at,
            extra: $this->referral($key),
            loadTest: $tag + ['seeded' => true],
        );

        Carbon::setTestNow($at->addSeconds(random_int(20, 90))->setTimezone(config('app.timezone')));
        $c = $message->conversation()->first();

        app(BotEngine::class)->handover($c, 'intent', $opener, null, null, [
            'category' => $scenario['category'], 'priority' => 'medium', 'queue' => 'agents',
        ]);

        $run->increment('seeded');
    }

    /**
     * Stores the plan on the active run (a new run when none) and emits its first wave now; the
     * scheduler's tick emits the next ones. Starting again replaces the plan of the active run.
     *
     * @param  array{every:int, count:int, hours:int, spread:int}  $plan
     */
    public function start(array $plan): LoadTestRun
    {
        LoadTestChannels::ensureAll();
        $run = LoadTestRun::activeOrStart();
        $run->forceFill([
            'plan' => $plan,
            'next_wave_at' => now(),
            'waves_until' => now()->addHours($plan['hours']),
        ])->save();

        $this->tick();

        return $run->fresh();
    }

    /**
     * The scheduler's every-minute call: emits the active run's wave when it is due. At most one
     * wave per call — after a scheduler gap the next one follows `every` minutes later instead of
     * a flood of the missed ones. Returns the openers it queued (0 = nothing was due).
     */
    public function tick(): int
    {
        if (! self::enabled()) {
            return 0;
        }

        $run = LoadTestRun::query()->where('status', LoadTestRun::ACTIVE)
            ->whereNotNull('next_wave_at')->where('next_wave_at', '<=', now())
            ->latest('id')->first();

        if ($run === null || ! is_array($run->plan)) {
            return 0;
        }

        $plan = $run->plan;
        $due = $run->next_wave_at;

        if ($run->waves_until === null || $due->greaterThanOrEqualTo($run->waves_until)) {
            $run->forceFill(['next_wave_at' => null])->save();

            return 0;
        }

        $next = $due->copy()->addMinutes($plan['every']);

        if ($next->lessThanOrEqualTo(now())) {
            $next = now()->addMinutes($plan['every']); // missed waves are skipped, not replayed
        }

        // Claimed before anything is queued, so an overlapping tick can never emit the same wave twice.
        $claimed = LoadTestRun::query()->whereKey($run->id)->where('status', LoadTestRun::ACTIVE)->where('next_wave_at', $due)
            ->update(['next_wave_at' => $next->lessThan($run->waves_until) ? $next : null]);

        if ($claimed !== 1) {
            return 0;
        }

        $sent = $this->emitWave($run, (int) $plan['count'], (int) $plan['spread']);

        LoadTestRun::query()->whereKey($run->id)->update([
            'waves_done' => DB::raw('waves_done + 1'),
            'openers_sent' => DB::raw('openers_sent + '.$sent),
        ]);

        return $sent;
    }

    /** $count new customers, their openers queued over $spread minutes through the webhook pipeline. */
    private function emitWave(LoadTestRun $run, int $count, int $spread): int
    {
        for ($i = 0; $i < $count; $i++) {
            $key = Scenarios::randomKey();
            $name = Scenarios::randomName();
            $customerKey = $this->customerKey($run, 'w');
            $tag = $this->tag($run, $key, $name, $customerKey);

            $this->simulator->queueCustomerMessage(
                Scenarios::randomPlatform(), $customerKey, $name, Scenarios::render(Scenarios::get($key)['opener'], $tag['order_number']),
                $count > 1 ? intdiv($i * $spread * 60, $count) : 0,
                extra: $this->referral($key),
                loadTest: $tag,
            );
        }

        return $count;
    }

    /** Ends the active run: no more waves; its queued openers and follow-ups do nothing when they run. */
    public function stop(): ?LoadTestRun
    {
        $run = LoadTestRun::active();

        $run?->forceFill(['status' => LoadTestRun::STOPPED, 'stopped_at' => now(), 'next_wave_at' => null])->save();

        return $run;
    }

    /**
     * Whether a load-test line tagged with this run may still be delivered: the gate is on and
     * its run is the active one.
     */
    public static function runIsLive(mixed $runId): bool
    {
        return self::enabled() && is_numeric($runId)
            && LoadTestRun::query()->whereKey((int) $runId)->where('status', LoadTestRun::ACTIVE)->exists();
    }

    /**
     * The active run's figures for `status`.
     *
     * @return array{run: LoadTestRun, waves_total: int, chats: int, open: int, closed: int}|null
     */
    public function status(): ?array
    {
        $run = LoadTestRun::active();

        if ($run === null) {
            return null;
        }

        $chats = Conversation::query()->where('meta->load_test->run', $run->id);
        $closed = (clone $chats)->where(fn ($q) => $q->where('status', ConversationStatus::Resolved->value)
            ->orWhere(fn ($q) => $q->whereNotNull('queue_entry_id')->whereNotExists(fn ($e) => $e->selectRaw('1')->from('queue_entries')
                ->whereColumn('queue_entries.conversation_id', 'conversations.id')->whereIn('queue_entries.status', ['waiting', ...QueueEntry::OPEN_STATUSES]))));
        $total = $chats->count();
        $closedCount = $closed->count();
        $plan = $run->plan;

        return [
            'run' => $run,
            'waves_total' => is_array($plan) ? (int) ceil(((int) $plan['hours'] * 60) / max(1, (int) $plan['every'])) : 0,
            'chats' => $total,
            'open' => $total - $closedCount,
            'closed' => $closedCount,
        ];
    }

    /** Her conversation, opened as a person's: the opener then reaches no bot turn. */
    private function openAsHuman(Platform $platform, string $customerKey, string $name): void
    {
        $account = LoadTestChannels::account($platform);
        $identity = app(CustomerResolver::class)->resolve($platform, $customerKey, $name);

        DB::transaction(fn () => app(InboxIngestor::class)->openConversationFor($identity, $account)
            ->forceFill(['handler' => Handler::Human])->save());
    }

    /** @return array<string, mixed> */
    public function referral(string $scenario): array
    {
        $referral = Scenarios::referral($scenario);

        return $referral !== null ? ['referral' => $referral] : [];
    }

    /**
     * What the opener tags her conversation with. A scenario that names her order gets a fake order
     * number (LoadTestTagger then makes that test order for her), never a real customer's.
     *
     * @return array{run:int, scenario:string, name:string, customer_key:string, order_number:?string}
     */
    public function tag(LoadTestRun $run, string $scenario, string $name, string $customerKey): array
    {
        return [
            'run' => $run->id, 'scenario' => $scenario, 'name' => $name, 'customer_key' => $customerKey,
            'order_number' => Scenarios::needsOrder($scenario) ? Scenarios::fakeOrderNumber() : null,
        ];
    }

    /** A brand-new customer of this run: every opener starts a new chat. */
    public function customerKey(LoadTestRun $run, string $kind): string
    {
        return 'lt'.$run->id.'-'.$kind.'-'.Str::lower(Str::random(10));
    }
}
