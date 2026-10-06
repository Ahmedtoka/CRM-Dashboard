<?php

namespace App\Today;

use App\Ads\Health\DataHealth;
use App\Ads\Launch\LaunchCounters;
use App\Enums\OrderStatus;
use App\Http\Support\DateRange;
use App\Inbox\ConversationQuery;
use App\Models\AdAccount;
use App\Models\Order;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\RatingStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * «يحتاج تدخل الآن» (wireframe 04 §4): what needs the manager now, only the items above zero, each linked to an
 * existing screen with the filter that lists exactly those rows. Counts reuse the target screen's own query where
 * one exists (the inbox's ConversationQuery), so the number and the list never disagree.
 */
final class UrgentStrip
{
    public const ORDER = ['overdue_windows', 'lounge', 'low_ratings', 'unpaid_orders', 'cases_overdue', 'ads_sync', 'launches'];

    public const PAYMENT_WAIT_MINUTES = 120;

    public const PAYMENT_LOOKBACK_DAYS = 7;

    public function __construct(
        private readonly ConversationQuery $conversations,
        private readonly RatingStats $ratings,
        private readonly DataHealth $health,
    ) {}

    /** @return list<array<string, mixed>> */
    public function for(User $u, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $today = $now->setTimezone(TodayWindow::TZ)->toDateString();
        $items = [];

        if (QueueSetting::current()->enabled) {
            $items[] = ['key' => 'overdue_windows', 'count' => $this->conversations->filtered($u, ['queue' => 'overdue'])->count(), 'tone' => 'danger', 'href' => '/inbox?queue=overdue'];
            $items[] = ['key' => 'lounge', 'count' => $this->conversations->filtered($u, ['queue' => 'waiting'])->count(), 'tone' => 'warn', 'href' => '/inbox?queue=waiting', 'longest_wait_seconds' => $this->longestWait($now)];
        }

        $items[] = [
            'key' => 'low_ratings', 'tone' => 'danger',
            'count' => $this->ratings->summary(DateRange::startOfCairoDay($today), $now)['low'],
            'href' => "/reports/team?from={$today}&to={$today}&stars=low#ratings",
        ];

        $since = CarbonImmutable::parse($today, TodayWindow::TZ)->subDays(self::PAYMENT_LOOKBACK_DAYS - 1)->toDateString();
        $items[] = [
            'key' => 'unpaid_orders', 'tone' => 'warn',
            'count' => Order::query()->where('status', OrderStatus::AwaitingPayment->value)
                ->where('created_at', '>=', DateRange::startOfCairoDay($since))
                ->where('created_at', '<=', $now->subMinutes(self::PAYMENT_WAIT_MINUTES))->count(),
            'href' => '/orders?status=awaiting_payment&older_than='.self::PAYMENT_WAIT_MINUTES.'&from='.$since,
        ];

        $items[] = [
            'key' => 'cases_overdue', 'tone' => 'danger',
            'count' => SupportCase::query()->where('status', '!=', 'closed')->whereNotNull('sla_due_at')->where('sla_due_at', '<', $now)->count(),
            'href' => '/cases?overdue=1',
        ];

        $items[] = $this->adsSync();

        $items[] = ['key' => 'launches', 'tone' => 'warn', 'count' => (int) (LaunchCounters::for($u)['awaiting_approval'] ?? 0), 'href' => route('ads.approvals.index', absolute: false)];

        return array_values(array_filter($items, fn (array $i) => $i['count'] > 0));
    }

    /** Oldest customer waiting now, the night's backlog left out (as the board's «أقدم واحدة»). */
    private function longestWait(CarbonImmutable $now): ?int
    {
        $oldest = QueueEntry::query()->where('status', 'waiting')->where('priority', '!=', 'overnight')->min('enqueued_at');

        return $oldest ? max(0, (int) Carbon::parse($oldest, config('app.timezone'))->diffInSeconds($now)) : null;
    }

    /** @return array<string, mixed> */
    private function adsSync(): array
    {
        $accounts = AdAccount::query()->with('connection')->where('platform', 'meta')->where('is_active', true)->get();
        $stale = collect($accounts->isEmpty() ? [] : $this->health->accountChecks($accounts, ['stale']))->filter->isBad();

        return [
            'key' => 'ads_sync', 'tone' => 'warn', 'count' => $stale->count(),
            'age_minutes' => $stale->isEmpty() ? null : (int) $stale->max(fn ($c) => (int) ($c->detail['age_minutes'] ?? 0)),
            'href' => route('ads.sync', absolute: false),
        ];
    }
}
