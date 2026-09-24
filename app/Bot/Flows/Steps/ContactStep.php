<?php

namespace App\Bot\Flows\Steps;

use App\Bot\ArabicNormalizer;
use App\Bot\Flows\EntityExtractor;
use App\Bot\Flows\FlowAnswerResolver;
use App\Bot\Flows\FlowPrompter;
use App\Models\Conversation;
use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * `contact` (the owner's complaint flow, 2026-09-19): the name and mobile the team calls her on,
 * saved as `name` and `phone`.
 *
 *  - A verified order in the flow: taken from the order, nothing asked.
 *  - Both known on her profile (the channel's name, the phone from WhatsApp or Shopify):
 *    «هنتواصل مع حضرتك باسم «سارة» على رقم 0106•••6611 — تمام كده؟» [أيوه تمام] [رقم تاني];
 *    «رقم تاني» asks for the mobile only.
 *  - Otherwise one question for both: «ممكن اسم حضرتك ورقم الموبايل اللي نتواصل عليه؟» (owner's wording, 2026-09-24);
 *    the name and the Egyptian mobile (Arabic digits too) are read from that one message, and
 *    only the missing one is asked for next.
 *
 * Step state: `data.contact_pending` = {mode: confirm|both|name|phone, name?, phone?}.
 */
final class ContactStep extends BaseStep
{
    public const CONFIRM_TEXT = 'هنتواصل مع حضرتك باسم «%s» على رقم %s — تمام كده؟';

    public const ASK_BOTH_TEXT = 'ممكن اسم حضرتك ورقم الموبايل اللي نتواصل عليه؟ 🌸';

    public const ASK_NAME_TEXT = 'تمام 🌸 وممكن اسم حضرتك؟';

    public const ASK_PHONE_TEXT = 'ممكن رقم الموبايل اللي نتواصل مع حضرتك عليه؟ 📞';

    public const YES_BUTTON = 'أيوه تمام';

    public const OTHER_BUTTON = 'رقم تاني';

    /** Words around a name in "انا سارة ورقمي …" (cleaned, a leading و dropped). */
    private const FILLER = ['انا', 'اسمي', 'الاسم', 'اسم', 'رقمي', 'الرقم', 'رقم', 'موبايلي', 'الموبايل', 'موبايل', 'تليفوني', 'التليفون', 'تليفون', 'ده', 'دا', 'هو', 'هي', 'و', 'يا', 'فندم', 'حضرتك', 'معاكي', 'معاك', 'عليه', 'عليا', 'my', 'name', 'is', 'phone', 'number'];

    public function __construct(
        FlowPrompter $prompter,
        private readonly FlowAnswerResolver $resolver,
        private readonly ArabicNormalizer $normalizer,
    ) {
        parent::__construct($prompter);
    }

    public function enter(Conversation $c, array $state, array $step): StepOutcome
    {
        $data = $state['data'] ?? [];

        // Known from the order she proved is hers: the complaint goes on without asking.
        if (OrderStep::hasVerifiedOrder($data) && ($order = Order::with('customer')->find((int) $data['order_id'])) !== null) {
            $name = $this->validName((string) ($order->shipping_name ?: $order->customer?->name));
            $phone = EntityExtractor::phone((string) ($order->shipping_phone ?: $order->customer?->phone));

            if ($name !== null && $phone !== null) {
                return StepOutcome::continue(['name' => $name, 'phone' => $phone, 'contact_source' => 'order', 'contact_pending' => null]);
            }
        }

        $customer = $c->customer;
        $name = $this->validName((string) $customer?->name);
        $phone = EntityExtractor::phone((string) ($customer?->phone ?: $customer?->normalized_phone));

        $pending = $name !== null && $phone !== null
            ? ['mode' => 'confirm', 'name' => $name, 'phone' => $phone]
            : ['mode' => 'both'];

        $state['data']['contact_pending'] = $pending;

        return StepOutcome::wait([$this->prompt($state, $step)], 0, ['contact_pending' => $pending]);
    }

    public function prompt(array $state, array $step): array
    {
        $pending = $this->pending($state['data'] ?? []);

        return match ($pending['mode']) {
            'confirm' => ['text' => sprintf(self::CONFIRM_TEXT, $pending['name'], self::mask((string) $pending['phone'])), 'buttons' => [
                self::button(self::YES_BUTTON, "step:{$state['key']}:{$state['step']}:yes"),
                self::button(self::OTHER_BUTTON, "step:{$state['key']}:{$state['step']}:other"),
            ]],
            'name' => ['text' => self::ASK_NAME_TEXT, 'buttons' => []],
            'phone' => ['text' => self::ASK_PHONE_TEXT, 'buttons' => []],
            default => ['text' => trim((string) ($step['text'] ?? '')) !== '' ? $this->prompter->renderText((string) $step['text'], $state['data'] ?? []) : self::ASK_BOTH_TEXT, 'buttons' => []],
        };
    }

    public function answer(Conversation $c, array $state, array $step, string $text, Collection $burst): ?StepOutcome
    {
        $pending = $this->pending($state['data'] ?? []);
        $phone = EntityExtractor::phone($text);

        if ($pending['mode'] === 'confirm') {
            if ($phone !== null) {
                return $this->done($pending['name'] ?? null, $phone, 'typed');
            }

            $clean = $this->resolver->clean($text);

            if ($this->resolver->yesNo($text) === 'yes' || in_array($clean, ['ايوه تمام', 'تمام كده', 'تمام كدا', 'مظبوط', 'صح'], true)) {
                return $this->done($pending['name'] ?? null, $pending['phone'] ?? null, 'profile');
            }

            return $this->resolver->yesNo($text) === 'no' || str_contains($clean, 'رقم تاني') || str_contains($clean, 'رقم تان') ? $this->askPhone($pending) : null;
        }

        $name = $this->nameIn($text);

        return match ($pending['mode']) {
            'name' => $name !== null ? $this->done($name, $phone ?? ($pending['phone'] ?? null), 'typed') : null,
            'phone' => $phone !== null ? $this->done($name ?? ($pending['name'] ?? null), $phone, 'typed') : null,
            default => match (true) {
                $name !== null && $phone !== null => $this->done($name, $phone, 'typed'),
                $phone !== null => StepOutcome::wait([['text' => self::ASK_NAME_TEXT]], 0, ['contact_pending' => ['mode' => 'name', 'phone' => $phone]]),
                $name !== null => StepOutcome::wait([['text' => self::ASK_PHONE_TEXT]], 0, ['contact_pending' => ['mode' => 'phone', 'name' => $name]]),
                default => null,
            },
        };
    }

    public function payload(Conversation $c, array $state, array $step, string $value): ?StepOutcome
    {
        $pending = $this->pending($state['data'] ?? []);

        if ($pending['mode'] !== 'confirm') {
            return null;
        }

        return match ($value) {
            'yes' => $this->done($pending['name'] ?? null, $pending['phone'] ?? null, 'profile'),
            'other' => $this->askPhone($pending),
            default => null,
        };
    }

    /** "01061236611" → "0106•••6611". */
    public static function mask(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) >= 8 ? substr($digits, 0, 4).'•••'.substr($digits, -4) : $phone;
    }

    /** The name in "سارة 01012345678" / "انا سارة احمد ورقمي ٠١٠…": the words left once the number and fillers are gone. */
    public function nameIn(string $text): ?string
    {
        $latin = $this->normalizer->digitsToLatin($text);
        $withoutNumbers = preg_replace('/\+?\d[\d\s\-]{5,}\d/u', ' ', $latin) ?? $latin;
        $withoutNumbers = preg_replace('/[\d:،,.\-_\/()]+/u', ' ', $withoutNumbers) ?? $withoutNumbers;
        $filler = array_map(fn (string $w) => $this->resolver->clean($w), self::FILLER);
        $kept = [];

        foreach (preg_split('/\s+/u', trim($withoutNumbers)) ?: [] as $word) {
            $clean = $this->resolver->clean($word);
            $bare = preg_replace('/^و(?=\p{L}{2})/u', '', $clean) ?? $clean;

            if ($clean === '' || in_array($clean, $filler, true) || in_array($bare, $filler, true)) {
                continue;
            }

            $kept[] = $word;
        }

        return $kept === [] ? null : $this->validName(implode(' ', array_slice($kept, 0, 4)));
    }

    private function validName(string $name): ?string
    {
        $name = trim($name);

        return $name !== '' ? $this->resolver->name($name) : null;
    }

    private function askPhone(array $pending): StepOutcome
    {
        return StepOutcome::wait([['text' => self::ASK_PHONE_TEXT]], 0, ['contact_pending' => ['mode' => 'phone', 'name' => $pending['name'] ?? null]]);
    }

    private function done(?string $name, ?string $phone, string $source): StepOutcome
    {
        return StepOutcome::continue(array_filter([
            'name' => $name,
            'phone' => $phone,
            'contact_source' => $source,
        ], fn ($v) => $v !== null) + ['contact_pending' => null]);
    }

    /** @return array{mode:string, name?:?string, phone?:?string} */
    private function pending(array $data): array
    {
        $p = $data['contact_pending'] ?? null;

        return is_array($p) && in_array($p['mode'] ?? null, ['confirm', 'both', 'name', 'phone'], true) ? $p : ['mode' => 'both'];
    }
}
