<?php

namespace App\Bot;

use App\Bot\ArabicNormalizer;
use App\Bot\Episodes\ConversationEpisode;
use App\Bot\Flows\EntityExtractor;
use App\Bot\Flows\FlowLabels;
use App\Bot\Flows\HumanHandover;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SupportCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Round 4 (2026-10-24), the owner: «يحوّل لموظف ومعاه معلومات». Chat 702 on the live server: the
 * moderator got the complaint type, the order and the pieces, but not what the customer wanted, how she
 * felt, or her last words — so she re-read the thread. Every handover (keyword, the agent's tool, unsure
 * twice, the turn limit, a flow's handover step, on both routers) now ends in BotEngine::handover, and
 * this brief adds, as «• label: value» lines (HandoverDigest reads them for the window card):
 *
 *   الموضوع · عايزة إيه · الأوردر · طلبها المفتوح · البوت جاوب على · مزاجها · آخر كلامها
 *
 * A line whose label the caller already wrote (a flow's own «• رقم الأوردر: …», «• القطع: …») is not
 * added twice. Everything here is read from the chat and the flow data — no model call.
 */
final class HandoverBrief
{
    public const LABEL_TOPIC = 'الموضوع';

    public const LABEL_WANTS = 'عايزة إيه';

    public const LABEL_ORDER = 'الأوردر';

    public const LABEL_OPEN = 'طلبها المفتوح';

    public const LABEL_ANSWERED = 'البوت جاوب على';

    public const LABEL_MOOD = 'مزاجها';

    public const LABEL_LAST = 'آخر كلامها';

    public const MOODS = ['angry' => 'غضبانة', 'negative' => 'زعلانة', 'neutral' => 'عادية', 'positive' => 'مبسوطة'];

    /** Normalized words that read as anger / unhappiness when the planner left no sentiment. */
    private const ANGRY = ['زفت', 'نصب', 'حرامي', 'فضيحه', 'هشتكي', 'حقي', 'اخركوا', 'اخركم', 'مهزله', 'هفضحكم', 'محامي', 'حمايه المستهلك', 'بلا بوت', 'استهزاء', 'قلة ذوق', 'قله ذوق'];

    private const NEGATIVE = ['اتاخر', 'اتأخر', 'مشكله', 'زعلانه', 'مش كويس', 'مستنيه', 'محدش', 'وحش', 'غلط', 'اتخصم', 'ناقص', 'مكسور', 'معيوب', 'مش راضيه', 'مش عاجبني', 'تعبت', 'زهقت', 'للاسف'];

    /** How a flow's `complaint_type` reads in the topic. */
    private const COMPLAINT_TYPES = ['product' => 'منتج', 'service' => 'خدمة', 'branch' => 'فرع', 'delivery' => 'التوصيل', 'staff' => 'موظف'];

    private const KINDS = ['return' => 'مرتجع', 'exchange' => 'استبدال', 'mixed' => 'مرتجع واستبدال', 'return_or_exchange' => 'مرتجع أو استبدال'];

    /** @var Collection<int, string> the button titles she tapped this episode (set by build()) */
    private Collection $tapped;

    public function __construct(private readonly ArabicNormalizer $normalizer = new ArabicNormalizer)
    {
        $this->tapped = collect();
    }

    /**
     * @param  list<string>  $existing  summary lines the caller already has (a flow's collected data)
     * @param  array{key?:string, step?:string, data?:array<string, mixed>}|null  $flow  the flow she was in when handed over
     * @return array{lines: list<string>, topic: ?string, order_number: ?string}
     */
    public function build(Conversation $c, string $reason, string $category, ?string $customerText, array $existing = [], ?array $flow = null): array
    {
        $floor = ConversationEpisode::historyFloor($c);
        $lastStaff = (int) $c->messages()->where('sender_type', SenderType::User->value)->max('id');
        $customer = $this->customerLines($c, $floor, $lastStaff);
        $texts = $customer->pluck('body')->map(fn ($b) => trim((string) $b))->filter()->values();
        $this->tapped = $customer->filter(fn (Message $m) => filled($m->payload))->pluck('body')->map(fn ($b) => trim((string) $b))->values();
        $data = (array) ($flow['data'] ?? []);
        $episode = ($c->bot_state ?? [])[ConversationEpisode::KEY] ?? [];
        $relates = is_array($episode) ? (array) ($episode['relates'] ?? []) : [];
        $open = ($relates['decision'] ?? null) === 'case' ? (string) ($relates['label'] ?? '') : '';

        $orders = $this->orderNumbers($c, $data, $texts, $open);
        $topic = $this->topic($c, $flow, $category, $orders, $open, $texts, $customerText);
        $wants = $this->wants($c, $flow, $texts, $customerText, $topic);
        $answered = $this->answered($c, $floor, $lastStaff, $customer->last()?->id);
        $mood = $this->mood($c, $texts);
        $last = $texts->reverse()->take(2)->reverse()->map(fn (string $t) => '«'.Str::limit(preg_replace('/\s+/u', ' ', $t) ?? $t, 120).'»')->implode(' / ');

        $lines = [];
        $add = function (string $label, ?string $value) use (&$lines, $existing): void {
            $value = trim((string) $value);

            if ($value === '' || $this->has($existing, $label)) {
                return;
            }

            $lines[] = '• '.$label.': '.$value;
        };

        $add(self::LABEL_TOPIC, $topic);
        $add(self::LABEL_WANTS, $wants);

        if ($orders !== [] && ! $this->has($existing, 'رقم الأوردر')) {
            $add(self::LABEL_ORDER, implode('، ', array_map(fn ($n) => '#'.$n, $orders)));
        }

        $add(self::LABEL_OPEN, $open);
        $add(self::LABEL_ANSWERED, $answered);
        $add(self::LABEL_MOOD, self::MOODS[$mood] ?? $mood);
        $add(self::LABEL_LAST, $last);

        return ['lines' => $lines, 'topic' => $topic, 'order_number' => count($orders) === 1 ? $orders[0] : null];
    }

    /** The word for a mood key (the planner's sentiment, or what the words say). */
    public function mood(Conversation $c, Collection $texts): string
    {
        $planned = ($c->bot_state ?? [])['router']['sentiment'] ?? null;

        if (is_string($planned) && isset(self::MOODS[$planned]) && $planned !== 'neutral') {
            return $planned;
        }

        $norm = $this->normalizer->normalize($texts->reverse()->take(3)->implode("\n"));

        foreach (self::ANGRY as $w) {
            if (str_contains($norm, $this->normalizer->normalize($w)) || substr_count($norm, '!') >= 2) {
                return 'angry';
            }
        }

        foreach (self::NEGATIVE as $w) {
            if (str_contains($norm, $this->normalizer->normalize($w))) {
                return 'negative';
            }
        }

        return is_string($planned) && isset(self::MOODS[$planned]) ? $planned : 'neutral';
    }

    /** @return Collection<int, Message> her messages of this episode (after the team's last line), oldest first, at most 6 */
    private function customerLines(Conversation $c, ?int $floor, int $lastStaff): Collection
    {
        return $c->messages()->where('direction', MessageDirection::In->value)->where('sender_type', SenderType::Customer->value)
            ->when($floor, fn ($q, $f) => $q->where('id', '>=', $f))
            ->when($lastStaff > 0, fn ($q) => $q->where('id', '>', $lastStaff))
            ->whereNotNull('body')->orderByDesc('id')->limit(6)->get(['id', 'body', 'payload'])->reverse()->values();
    }

    /** @return list<string> order numbers (digits) from the flow, her words and the newest case of this chat */
    private function orderNumbers(Conversation $c, array $data, Collection $texts, string $open): array
    {
        $numbers = [];

        if (filled($data['order_number'] ?? null)) {
            // The order the flow verified is the order: a 4-digit answer («4567») is not another one.
            $numbers[] = ltrim((string) $data['order_number'], '#');
        } else {
            foreach ($texts as $t) {
                foreach (EntityExtractor::orderRefsInContext($t) as $ref) {
                    $numbers[] = $ref;
                }
            }
        }

        if ($numbers === [] && $open !== '' && preg_match('/أوردر #(\d+)/u', $open, $m) === 1) {
            $numbers[] = $m[1];
        }

        if ($numbers === [] && $c->exists) {
            $case = SupportCase::query()->where('conversation_id', $c->id)->where('created_at', '>=', now()->subDay())->latest('id')->first(['order_number']);

            if ($case !== null && filled($case->order_number)) {
                $numbers[] = ltrim((string) $case->order_number, '#');
            }
        }

        return array_values(array_unique(array_filter($numbers)));
    }

    /** «شكوى عن منتج — أوردر #209129», «مرتجع — أوردر #209129», her own topic words, or her first line. */
    private function topic(Conversation $c, ?array $flow, string $category, array $orders, string $open, Collection $texts, ?string $customerText): ?string
    {
        $own = trim((string) $c->handover_topic);

        if ($own !== '') {
            return $own;
        }

        if ($flow !== null && filled($flow['key'] ?? null)) {
            $data = (array) ($flow['data'] ?? []);
            $label = FlowLabels::of((string) $flow['key']);

            if ($flow['key'] === 'complaint') {
                $type = self::COMPLAINT_TYPES[(string) ($data['complaint_type'] ?? '')] ?? ($data['complaint_type_title'] ?? null);
                $label = 'شكوى'.($type ? ' عن '.$type : '');
            } elseif ($flow['key'] === 'return_exchange') {
                $label = self::KINDS[(string) ($data['request_kind'] ?? '')] ?? $label;
            }

            return $label.($orders !== [] ? ' — أوردر #'.$orders[0] : '');
        }

        if ($open !== '') {
            return 'بخصوص '.$open;
        }

        // The first thing she said this episode that is not «I want a person».
        $first = $texts->first(fn (string $t) => ! app(HumanHandover::class)->isHumanRequest($t) && mb_strlen($t) > 3);

        if ($first !== null) {
            return Str::limit(preg_replace('/\s+/u', ' ', $first) ?? $first, 120);
        }

        $label = HandoverSummary::categoryLabel($category);

        return $label !== '' && $label !== $category ? $label : null;
    }

    /** What she wants, in her words or the flow's, and where she stopped. */
    private function wants(Conversation $c, ?array $flow, Collection $texts, ?string $customerText, ?string $topic): ?string
    {
        $human = app(HumanHandover::class);
        // Her own typed words (a tapped button's title is not what she wants).
        $said = $texts->reverse()->first(fn (string $t) => ! $human->isHumanRequest($t) && mb_strlen($t) > 3 && ! $this->tapped->contains($t));
        $parts = [];

        if ($flow !== null && filled($flow['key'] ?? null)) {
            // The step she stopped at: the flow's last question (the message right before her request for a person).
            $question = (string) $c->messages()->where('sender_type', SenderType::Bot->value)->latest('id')->value('body');
            $parts[] = $topic !== null ? $topic : FlowLabels::of((string) $flow['key']);

            if (trim($question) !== '' && ! str_contains($question, 'تحويل')) {
                $parts[] = 'وقفت عند «'.Str::limit(preg_replace('/\s+/u', ' ', $question) ?? $question, 90).'»';
            }
        }

        if ($said !== null && ($flow === null || ! in_array($said, $parts, true))) {
            $parts[] = '«'.Str::limit(preg_replace('/\s+/u', ' ', $said) ?? $said, 160).'»';
        } elseif ($parts === [] && $customerText !== null && trim($customerText) !== '') {
            $parts[] = '«'.Str::limit(preg_replace('/\s+/u', ' ', trim($customerText)) ?? $customerText, 160).'»';
        }

        return $parts === [] ? null : implode('، ', $parts);
    }

    /** The bot's last two statements before her last message (its questions are not answers). */
    private function answered(Conversation $c, ?int $floor, int $lastStaff, ?int $beforeId): ?string
    {
        $lines = $c->messages()->where('sender_type', SenderType::Bot->value)
            ->when($floor, fn ($q, $f) => $q->where('id', '>=', $f))
            ->when($lastStaff > 0, fn ($q) => $q->where('id', '>', $lastStaff))
            ->when($beforeId, fn ($q, $id) => $q->where('id', '<', $id))
            ->whereNotNull('body')->orderByDesc('id')->limit(8)->pluck('body')
            ->map(fn ($b) => trim(preg_replace('/\s+/u', ' ', (string) $b) ?? ''))
            ->filter(fn (string $t) => $t !== '' && preg_match('/[?؟]\s*$/u', $t) !== 1 && ! str_contains($t, 'ثانية واحدة'))
            ->take(2)->reverse()->values();

        return $lines->isEmpty() ? null : $lines->map(fn (string $t) => '«'.Str::limit($t, 100).'»')->implode(' / ');
    }

    /** @param  list<string>  $lines */
    private function has(array $lines, string $label): bool
    {
        foreach ($lines as $line) {
            if (str_starts_with(ltrim(trim((string) $line), '• '), $label.':')) {
                return true;
            }
        }

        return false;
    }
}
