<?php

namespace App\Simulator\LoadTest;

use App\Bot\BotEngine;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Inbox\CustomerResolver;
use App\Inbox\InboxIngestor;
use App\Models\LoadTestRun;
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
        $run = LoadTestRun::activeOrStart();
        LoadTestChannels::ensureAll();

        $from = CarbonImmutable::now(self::TZ)->subDay()->setTimeFromTimeString(self::BACKLOG_FROM);
        $moments = collect(range(1, max(0, $count)))
            ->map(fn () => $from->addSeconds(random_int(0, self::BACKLOG_SECONDS)))
            ->sort()->values();

        $previous = Carbon::getTestNow();
        $seeded = 0;

        try {
            foreach ($moments as $at) {
                $this->seedOne($run, $at);
                $seeded++;
            }
        } finally {
            Carbon::setTestNow($previous);
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

        $message = $this->simulator->customerMessage(
            $platform, $customerKey, $name, $scenario['opener'], $at,
            extra: $this->referral($key),
            loadTest: $this->tag($run, $key, $name, $customerKey) + ['seeded' => true],
        );

        Carbon::setTestNow($at->addSeconds(random_int(20, 90))->setTimezone(config('app.timezone')));
        $c = $message->conversation()->first();

        app(BotEngine::class)->handover($c, 'intent', $scenario['opener'], null, null, [
            'category' => $scenario['category'], 'priority' => 'medium', 'queue' => 'agents',
        ]);

        $run->increment('seeded');
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

    /** @return array{run:int, scenario:string, name:string, customer_key:string} */
    public function tag(LoadTestRun $run, string $scenario, string $name, string $customerKey): array
    {
        return ['run' => $run->id, 'scenario' => $scenario, 'name' => $name, 'customer_key' => $customerKey];
    }

    /** A brand-new customer of this run: every opener starts a new chat. */
    public function customerKey(LoadTestRun $run, string $kind): string
    {
        return 'lt'.$run->id.'-'.$kind.'-'.Str::lower(Str::random(10));
    }
}
