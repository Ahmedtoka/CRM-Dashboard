<?php

namespace App\Cases\Returns;

use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Order;
use App\Models\SupportCase;
use App\Models\SupportCaseEvent;
use App\Models\User;
use App\Queue\Cases\CaseResolution;
use App\Support\SafeBroadcast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Returns and exchanges end to end (round 2026-10-24, the owner's facts of 2026-10-24): the refund is cash the
 * courier hands over at pickup, and shipping facts come from Shopify only.
 *
 *   requested → pickup_scheduled (a date the moderator picks) → picked_up (cash refunded for a return)
 *             → received (back at the warehouse) → [exchange_shipped: the new order] → closed
 *   any open stage before pickup, or a pickup not yet done → cancelled (a reason)
 *
 * Every step is validated in order (ReturnTransitionException otherwise), stamped on the case, written as a
 * SupportCaseEvent, noted on the conversation, and the customer is told with the ReturnScripts text for the step
 * (SendReturnUpdate, which respects the reply window and a running moderator window). `closed` resolves the case
 * through CaseResolution (the moderator's points, as for any resolved case); `cancelled` closes it.
 */
final class ReturnLifecycle
{
    public const STAGES = ['requested', 'pickup_scheduled', 'picked_up', 'received', 'exchange_shipped', 'closed', 'cancelled'];

    public const TERMINAL = ['closed', 'cancelled'];

    /** The steps a moderator may take from each stage; `received` splits by kind (see nextStages()). */
    private const NEXT = [
        'requested' => ['pickup_scheduled', 'cancelled'],
        'pickup_scheduled' => ['pickup_scheduled', 'picked_up', 'cancelled'],
        'picked_up' => ['received'],
        'received' => ['exchange_shipped', 'closed'],
        'exchange_shipped' => ['closed'],
        'closed' => [],
        'cancelled' => [],
    ];

    /** Arabic day and month names for {pickup_date} (Carbon's own `ar` locale is not Egyptian in every name). */
    private const DAYS = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

    private const MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

    public function __construct(private readonly CaseResolution $resolution) {}

    public static function stageOf(SupportCase $case): string
    {
        return in_array($case->return_stage, self::STAGES, true) ? $case->return_stage : 'requested';
    }

    /** An exchange ships a new piece; a plain return only takes one back. */
    public static function isExchange(SupportCase $case): bool
    {
        if ($case->type === 'exchange') {
            return true;
        }

        if ($case->type !== 'return_exchange') {
            return false;
        }

        $data = is_array($case->data) ? $case->data : [];

        return ($data['request'] ?? null) === 'exchange' || isset($data['exchange_product']) || ! empty($data['exchange_products'])
            || collect((array) ($data['selected_items'] ?? []))->contains(fn ($i) => is_array($i) && ($i['action'] ?? null) === 'exchange');
    }

    /** @return list<string> the stages the moderator may move this case to now */
    public static function nextStages(SupportCase $case): array
    {
        if (! $case->isReturnLike()) {
            return [];
        }

        $next = self::NEXT[self::stageOf($case)] ?? [];
        $exchange = self::isExchange($case);

        return array_values(array_filter($next, fn (string $s) => match ($s) {
            'exchange_shipped' => $exchange,
            // A return closes once the piece is back; an exchange only once the new one shipped.
            'closed' => ! $exchange || self::stageOf($case) === 'exchange_shipped',
            default => true,
        }));
    }

    /**
     * @param  array<string, mixed>  $input  pickup_date (Y-m-d), refund_amount, exchange_order_id, reason
     *
     * @throws ReturnTransitionException
     */
    public function advance(SupportCase $case, string $stage, User $by, array $input = []): SupportCase
    {
        if (! $case->isReturnLike()) {
            throw new ReturnTransitionException('not_a_return');
        }

        if (! in_array($stage, self::STAGES, true) || $stage === 'requested') {
            throw new ReturnTransitionException('unknown_stage');
        }

        return DB::transaction(function () use ($case, $stage, $by, $input) {
            $locked = SupportCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            if (! in_array($stage, self::nextStages($locked), true)) {
                throw new ReturnTransitionException('out_of_order', "Cannot move case {$locked->id} from ".self::stageOf($locked)." to {$stage}.");
            }

            $meta = $this->validate($locked, $stage, $input);
            $now = CarbonImmutable::now();

            $locked->forceFill(array_filter([
                'return_stage' => $stage,
                'return_stage_at' => $now,
                'pickup_date' => $meta['pickup_date'] ?? $locked->pickup_date?->toDateString(),
                'refund_amount' => array_key_exists('refund_amount', $meta) ? $meta['refund_amount'] : $locked->refund_amount,
                'exchange_order_id' => $meta['exchange_order_id'] ?? $locked->exchange_order_id,
            ], fn ($v) => $v !== null))->save();

            [$scriptKey, $vars] = $this->script($locked, $stage, $meta);

            $event = SupportCaseEvent::create([
                'support_case_id' => $locked->id,
                'stage' => $stage,
                'happened_at' => $now,
                'user_id' => $by->id,
                'meta' => $meta + ['script_vars' => $vars],
                'script_key' => $scriptKey,
            ]);

            ConversationNote::create([
                'conversation_id' => $locked->conversation_id,
                'user_id' => null,
                'body' => $this->note($locked, $stage, $meta, $by),
                'mentions' => [],
            ]);

            $this->syncStatus($locked, $stage, $by);

            $caseId = $locked->id;
            $eventId = $event->id;
            $conversationId = $locked->conversation_id;

            DB::afterCommit(function () use ($scriptKey, $eventId, $conversationId) {
                if ($scriptKey !== null) {
                    SendReturnUpdate::dispatch($eventId);
                }

                rescue(function () use ($conversationId) {
                    $c = Conversation::query()->find($conversationId);
                    if ($c !== null) {
                        SafeBroadcast::send(new ConversationUpdated($c));
                    }
                }, null, false);
            });

            return SupportCase::query()->findOrFail($caseId);
        }, attempts: 3);
    }

    /**
     * The step's facts, checked. @return array<string, mixed>
     *
     * @throws ReturnTransitionException
     */
    private function validate(SupportCase $case, string $stage, array $input): array
    {
        switch ($stage) {
            case 'pickup_scheduled':
                $raw = trim((string) ($input['pickup_date'] ?? ''));

                try {
                    $date = $raw === '' ? null : CarbonImmutable::createFromFormat('Y-m-d', $raw, 'Africa/Cairo')?->startOfDay();
                } catch (Throwable) {
                    $date = null;
                }

                if ($date === null || $date->format('Y-m-d') !== $raw) {
                    throw new ReturnTransitionException('pickup_date_required');
                }

                if ($date->lt(CarbonImmutable::now('Africa/Cairo')->startOfDay())) {
                    throw new ReturnTransitionException('pickup_date_past');
                }

                return ['pickup_date' => $date->toDateString()];

            case 'picked_up':
                $amount = $input['refund_amount'] ?? null;
                $exchange = self::isExchange($case);

                if ($amount === null || $amount === '') {
                    if (! $exchange) {
                        throw new ReturnTransitionException('refund_amount_required');
                    }

                    return ['refund_amount' => null, 'exchange' => true];
                }

                if (! is_numeric($amount) || (float) $amount < 0 || (float) $amount > 1000000) {
                    throw new ReturnTransitionException('refund_amount_invalid');
                }

                return ['refund_amount' => round((float) $amount, 2), 'exchange' => $exchange];

            case 'exchange_shipped':
                $id = $input['exchange_order_id'] ?? null;
                $order = is_numeric($id) ? Order::query()->find((int) $id) : null;

                if ($order === null || $order->id === $case->order_id) {
                    throw new ReturnTransitionException('exchange_order_required');
                }

                $tracking = $order->fulfillments()->whereNotNull('tracking_url')->orderByDesc('shopify_created_at')->value('tracking_url');

                return [
                    'exchange_order_id' => $order->id,
                    'exchange_order_number' => $order->shopify_order_name ?? $order->order_number ?? ('#'.$order->id),
                    'tracking_url' => is_string($tracking) && preg_match('#^https?://#i', $tracking) ? $tracking : null,
                ];

            case 'cancelled':
                $reason = trim((string) ($input['reason'] ?? ''));

                if ($reason === '' || mb_strlen($reason) > 200) {
                    throw new ReturnTransitionException('reason_required');
                }

                return ['reason' => $reason];

            default:
                return [];
        }
    }

    /**
     * The customer-facing script of the step and its variables; null when the step is internal (received, closed).
     *
     * @return array{0: ?string, 1: array<string, string>}
     */
    private function script(SupportCase $case, string $stage, array $meta): array
    {
        return match ($stage) {
            'pickup_scheduled' => ['return_pickup_scheduled', ['pickup_date' => self::arabicDate((string) $meta['pickup_date'])]],
            'picked_up' => ($meta['exchange'] ?? false)
                ? ['exchange_picked_up', []]
                : ['return_picked_up', ['amount' => self::pounds((float) $meta['refund_amount'])]],
            'exchange_shipped' => ['exchange_shipped', [
                'order_number' => ltrim((string) $meta['exchange_order_number'], '#'),
                'tracking' => ReturnScripts::trackingLine($meta['tracking_url'] ?? null),
            ]],
            'cancelled' => ['return_cancelled', ['reason' => (string) $meta['reason'], 'case_id' => (string) $case->id]],
            default => [null, []],
        };
    }

    /** The system note staff read in the thread (Arabic, as every recorded case text). */
    private function note(SupportCase $case, string $stage, array $meta, User $by): string
    {
        $kind = self::isExchange($case) ? 'الاستبدال' : 'المرتجع';
        $line = match ($stage) {
            'pickup_scheduled' => "اتحدد استلام {$kind} يوم ".self::arabicDate((string) $meta['pickup_date']),
            'picked_up' => isset($meta['refund_amount']) && $meta['refund_amount'] !== null
                ? 'المندوب استلم القطعة ورد '.self::pounds((float) $meta['refund_amount']).' جنيه كاش'
                : 'المندوب استلم القطعة',
            'received' => 'القطعة وصلت المخزن',
            'exchange_shipped' => 'القطعة الجديدة اتشحنت بأوردر '.($meta['exchange_order_number'] ?? ''),
            'closed' => "{$kind} اتقفل",
            'cancelled' => "{$kind} اتلغى، السبب: ".($meta['reason'] ?? ''),
            default => $stage,
        };

        return "طلب #{$case->id} ({$kind}): {$line} — بواسطة {$by->name}";
    }

    /** Open stages keep the case in progress; the end of the lifecycle resolves (closed) or closes (cancelled) it. */
    private function syncStatus(SupportCase $case, string $stage, User $by): void
    {
        if ($stage === 'closed') {
            $this->resolution->setStatus($case, 'resolved', $by, notify: false);

            return;
        }

        if ($stage === 'cancelled') {
            $this->resolution->setStatus($case, 'closed', $by);

            return;
        }

        if ($case->status === 'new') {
            $case->forceFill(['status' => 'in_progress'])->save();
        }
    }

    public static function arabicDate(string $ymd): string
    {
        $d = CarbonImmutable::parse($ymd, 'Africa/Cairo');

        return self::DAYS[$d->dayOfWeek].' '.$d->day.' '.self::MONTHS[$d->month - 1];
    }

    /** Whole pounds unless there are piastres. */
    public static function pounds(float $amount): string
    {
        return floor($amount) === $amount ? number_format($amount, 0, '.', '') : number_format($amount, 2, '.', '');
    }

    /**
     * The lifecycle as the UI shows it (SupportCaseResource `return`): null for a case that is not a return.
     *
     * @return array<string, mixed>|null
     */
    public static function present(SupportCase $case): ?array
    {
        if (! $case->isReturnLike()) {
            return null;
        }

        $case->loadMissing('events.user:id,name');
        if ($case->exchange_order_id !== null) {
            $case->loadMissing('exchangeOrder:id,order_number,shopify_order_name');
        }
        $exchange = $case->exchange_order_id !== null ? $case->exchangeOrder : null;

        return [
            'stage' => self::stageOf($case),
            'stage_at' => ($case->return_stage_at ?? $case->created_at)?->toIso8601String(),
            'is_exchange' => self::isExchange($case),
            'pickup_date' => $case->pickup_date?->toDateString(),
            'refund_amount' => $case->refund_amount !== null ? (float) $case->refund_amount : null,
            'exchange_order' => $exchange === null ? null : [
                'id' => $exchange->id,
                'number' => $exchange->shopify_order_name ?? $exchange->order_number,
            ],
            'next' => self::nextStages($case),
            'timeline' => array_merge(
                [['stage' => 'requested', 'at' => $case->created_at?->toIso8601String(), 'by' => null, 'meta' => [], 'notified' => null, 'notify_error' => null]],
                $case->events->map(fn (SupportCaseEvent $e) => [
                    'stage' => $e->stage,
                    'at' => $e->happened_at?->toIso8601String(),
                    'by' => $e->user_id !== null && $e->user ? $e->user->name : null,
                    'meta' => array_intersect_key((array) $e->meta, array_flip(['pickup_date', 'refund_amount', 'exchange_order_number', 'reason', 'tracking_url'])),
                    'notified' => $e->script_key === null ? null : $e->customer_notified_at !== null,
                    'notify_error' => $e->notify_error,
                ])->values()->all(),
            ),
        ];
    }
}
