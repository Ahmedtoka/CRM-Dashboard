<?php

namespace App\Bot\Episodes;

use App\Bot\ArabicNormalizer;
use App\Bot\Flow\BurstPolicy;
use App\Bot\Flow\Orders\OrderLookup;
use App\Bot\Flow\Orders\OrderStatusText;
use App\Bot\Flows\EntityExtractor;
use App\Bot\Flows\FlowLabels;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\SupportCase;

/**
 * Round 4 (2026-10-24), the owner: after the close and her rating, «يشوف الأول لو ليه حاجة قديمة
 * فيرد عليه بيها، ولا دي رسالة جديدة خالص». Her open requests — cases still open (a return, an
 * exchange, a complaint, a cancel/edit) and orders placed in the last days — and whether a new
 * message plausibly speaks about one of them:
 *
 *   strong  a word of that request's own family («المندوب مجاش» for a return, «محدش حللي» for a
 *           complaint), or its order number → the bot answers in its light at once;
 *   weak    only a follow-up phrase («طيب ايه اللي حصل؟», «وبعدين بقى») → «بتسألي عن طلب …؟» yes/no;
 *   none    a greeting, another order number, a plainly new question → a brand-new conversation.
 *
 * No model: words, normalized (ArabicNormalizer).
 */
final class OpenRequests
{
    public const CASE_DAYS = 30;

    public const ORDER_DAYS = 14;

    /** Follow-up phrases that could be about anything she has open (normalized). */
    private const COMMON = ['ايه اللي حصل', 'حصل ايه', 'فيه جديد', 'في جديد', 'اخبار', 'خبر', 'لسه', 'اتابع', 'متابعه', 'طلبي', 'الطلب', 'الموضوع', 'وبعدين', 'بقي', 'بقى', 'هيتم', 'هيحصل', 'محدش', 'حللي', 'حلو', 'اتحل', 'حل'];

    /** Words of each request family (normalized). */
    private const FAMILIES = [
        'return' => ['مندوب', 'المندوب', 'مجاش', 'ميجي', 'مجا', 'جه', 'استلم', 'يستلم', 'الاستلام', 'المرتجع', 'مرتجع', 'ارجع', 'الاسترجاع', 'استرجاع', 'الفلوس', 'فلوسي', 'المبلغ', 'استرداد', 'الرصيد', 'القطعه', 'هيوصل', 'يوصل', 'وصل'],
        'exchange' => ['مندوب', 'المندوب', 'مجاش', 'ميجي', 'مجا', 'جه', 'استلم', 'يستلم', 'الاستلام', 'الاستبدال', 'استبدال', 'ابدل', 'البديل', 'البديله', 'القطعه', 'المقاس', 'هيوصل', 'يوصل', 'وصل', 'الفلوس', 'الفرق'],
        'complaint' => ['الشكوي', 'شكوي', 'شكوه', 'المشكله', 'مشكله', 'حل', 'حللي', 'حلو', 'اتحل', 'محدش', 'اعتذار', 'تعويض', 'رد', 'ردوا', 'كلمني', 'اتصل', 'الفرع', 'المندوب'],
        'cancel_edit' => ['الالغاء', 'الغاء', 'اتلغي', 'اتلغى', 'التعديل', 'عدلت', 'اتعدل', 'العنوان', 'الاوردر', 'اوردر', 'الطلب'],
        'delivery_followup' => ['الاوردر', 'اوردر', 'وصل', 'هيوصل', 'يوصل', 'الشحنه', 'الشحن', 'المندوب', 'مندوب', 'اتاخر', 'فين', 'التوصيل'],
        'order' => ['الاوردر', 'اوردر', 'وصل', 'هيوصل', 'يوصل', 'الشحنه', 'الشحن', 'المندوب', 'مندوب', 'اتاخر', 'فين', 'التوصيل', 'الطلب', 'طلبي'],
    ];

    public function __construct(private readonly ArabicNormalizer $normalizer = new ArabicNormalizer) {}

    /**
     * Her open requests, newest first: open cases of this chat or customer (30 days), then orders of the
     * last days that have no case yet.
     *
     * @return list<array{kind:'case'|'order', id:int, type:string, label:string, order_number:?string, status:string, detail:string}>
     */
    public static function candidates(Conversation $c): array
    {
        if (! $c->exists) {
            return [];
        }

        $out = [];
        $cases = SupportCase::query()
            ->where(fn ($q) => $q->where('conversation_id', $c->id)->when($c->customer_id, fn ($q) => $q->orWhere('customer_id', $c->customer_id)))
            ->whereIn('status', SupportCase::OPEN_STATUSES)
            ->where('created_at', '>=', now()->subDays(self::CASE_DAYS))
            ->latest('id')->limit(3)->get();

        foreach ($cases as $case) {
            $pieces = collect((array) ($case->data['selected_items'] ?? []))->pluck('title')->filter()->implode('، ');
            $out[] = [
                'kind' => 'case', 'id' => (int) $case->id, 'type' => (string) $case->type,
                'label' => self::caseLabel($case), 'order_number' => filled($case->order_number) ? ltrim((string) $case->order_number, '#') : null,
                'status' => (string) $case->status,
                'detail' => trim('status '.$case->status.', opened '.$case->created_at?->toDateString().($pieces !== '' ? ', pieces: '.$pieces : '')),
            ];
        }

        $known = array_filter(array_column($out, 'order_number'));

        if ($c->customer_id) {
            $orders = Order::withLoadTest()->where('customer_id', $c->customer_id)
                ->where('placed_at', '>=', now()->subDays(self::ORDER_DAYS))
                ->whereNotIn('order_number', $known !== [] ? $known : ['-'])
                ->orderByDesc('placed_at')->limit(2)->get();

            foreach ($orders as $order) {
                $line = rescue(fn () => app(OrderStatusText::class)->line(app(OrderLookup::class)->ownedSnapshot($order)), null, report: false);
                $out[] = [
                    'kind' => 'order', 'id' => (int) $order->id, 'type' => 'order',
                    'label' => 'أوردر #'.ltrim((string) $order->order_number, '#').' (اتعمل '.$order->placed_at?->toDateString().')',
                    'order_number' => ltrim((string) $order->order_number, '#'), 'status' => (string) ($order->status?->value ?? $order->status),
                    'detail' => trim('placed '.$order->placed_at?->toDateString().($line ? ', '.$line : '')),
                ];
            }
        }

        return $out;
    }

    /**
     * How her message relates to the candidates.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return array{strength:'strong'|'weak'|'none', request:?array<string, mixed>}
     */
    public function relate(string $text, array $candidates): array
    {
        $none = ['strength' => 'none', 'request' => null];

        if ($candidates === [] || trim($text) === '' || app(BurstPolicy::class)->socialIntent($text) !== null) {
            return $none;
        }

        $norm = ' '.$this->normalizer->normalize($this->normalizer->digitsToLatin($text)).' ';
        $refs = EntityExtractor::orderRefs($text);

        if ($refs !== []) {
            foreach ($candidates as $r) {
                if ($r['order_number'] !== null && in_array(ltrim((string) $r['order_number'], '#'), $refs, true)) {
                    return ['strength' => 'strong', 'request' => $r];
                }
            }

            return $none; // another order: a new matter
        }

        $strong = [];
        $weak = [];

        foreach ($candidates as $r) {
            $family = self::FAMILIES[$r['type']] ?? (str_contains((string) $r['type'], 'return') || str_contains((string) $r['type'], 'exchange') ? [...self::FAMILIES['return'], ...self::FAMILIES['exchange']] : []);

            if ($this->hasAny($norm, $family)) {
                $strong[] = $r;
            } elseif ($this->hasAny($norm, self::COMMON)) {
                $weak[] = $r;
            }
        }

        if (count($strong) === 1) {
            return ['strength' => 'strong', 'request' => $strong[0]];
        }

        if ($strong !== []) {
            return ['strength' => 'weak', 'request' => $strong[0]]; // several could be meant: ask about the newest
        }

        return $weak !== [] ? ['strength' => 'weak', 'request' => $weak[0]] : $none;
    }

    /** «طلب مرتجع #812 (أوردر #209129)». */
    public static function caseLabel(SupportCase $case): string
    {
        $type = trans('cases.types.'.$case->type, [], 'ar');
        $type = is_string($type) && ! str_starts_with($type, 'cases.') ? $type : FlowLabels::of((string) $case->type);
        $order = filled($case->order_number) ? ' (أوردر #'.ltrim((string) $case->order_number, '#').')' : '';

        return 'طلب '.$type.' #'.$case->id.$order;
    }

    /** @param  list<string>  $words */
    private function hasAny(string $norm, array $words): bool
    {
        foreach ($words as $w) {
            $n = $this->normalizer->normalize($w);

            if ($n === '') {
                continue;
            }

            // Short words only as whole words (with an Arabic proclitic allowed): «حل» never inside «حلوة».
            if (mb_strlen($n) <= 3 ? preg_match('/(?<=\s)(?:و|ف|ب|ل|ال|وال)?'.preg_quote($n, '/').'(?=\s)/u', $norm) === 1 : str_contains($norm, $n)) {
                return true;
            }
        }

        return false;
    }
}
