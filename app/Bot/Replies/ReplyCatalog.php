<?php

namespace App\Bot\Replies;

use App\Bot\Agent\StoreAgent;
use App\Bot\Flows\FlowLabels;
use App\Bot\Language\ArabicOverrides;
use App\Bot\Language\TranslationMask;
use App\Bot\Language\TranslationSources;
use App\Models\BotFlow;
use App\Models\BotIntent;
use App\Models\BotKnowledgeEntry;
use App\Models\BotRule;
use Illuminate\Support\Collection;

/**
 * Everything the bot can say, on one page (owner, 2026-09-22: «أراجع عليها كلها بعين واحدة»):
 * one row per reply — when it is said, and the words — gathered from the knowledge entries
 * (scripts, policies), the intents that point at them, the guided flows' steps and the keyword
 * rules. A knowledge row is edited in place; a flow step and a rule link to their own editor,
 * because they are versioned / structured there.
 *
 * Row: {id, section, when, reply, source: entry|flow|rule, entry_id, key, active, edit_url, buttons}
 */
class ReplyCatalog
{
    public function __construct(private readonly ArabicOverrides $overrides, private readonly TranslationMask $mask) {}

    public const SECTIONS = ['agent', 'greeting', 'questions', 'facts', 'products', 'flows', 'flow_sentences', 'handover', 'rules', 'steps', 'status_words'];

    /** The step classes whose sentences are listed under «جمل الخطوات», with what each step is. */
    private const STEP_LABELS = [
        'ContactStep' => ['الاسم ورقم الموبايل', 'Name and mobile'],
        'OrderStep' => ['رقم الأوردر والتأكد منه', 'Order number and verification'],
        'OrderItemsStep' => ['اختيار قطع الأوردر', 'Picking the order\'s pieces'],
        'ProductLinkStep' => ['المنتج البديل', 'The replacement product'],
        'PhotoStep' => ['طلب الصورة', 'Asking for a photo'],
        'ItemChangesStep' => ['تعديل القطع', 'Changing pieces'],
        'StatusStep' => ['كارت حالة الأوردر', 'Order status card'],
        'AreaStep' => ['اختيار المنطقة', 'Picking the area'],
        'BranchStep' => ['اختيار الفرع', 'Picking the branch'],
        'BranchesListStep' => ['عرض الفروع', 'Listing branches'],
        'HumanHandover' => ['التحويل لموظف', 'Handover to a person'],
        'FlowEngine' => ['جمل عامة في الفلوهات', 'General flow sentences'],
        'FlowPrompter' => ['جمل عامة', 'General sentences'],
        'FlowLabels' => ['أسماء الفلوهات', 'Flow names'],
        'OrderStatusText' => ['كلمات حالة الأوردر', 'Order status words'],
        'DeliveryEstimate' => ['مواعيد التوصيل', 'Delivery estimates'],
    ];

    /** When a wording script is said, by key prefix or exact key (Arabic, shown to the owner). */
    private const WHEN = [
        'script.greeting_mirror_' => 'العميلة بدأت بالتحية دي — البوت بيردها الأول',
        'script.greeting' => 'أول رد في المحادثة بعد التحية (قبل القائمة)',
        'script.thanks' => 'العميلة قالت شكرًا',
        'script.products_intro' => 'ضغطت «الموديلات والأسعار» — الجملة اللي قبل كروت المنتجات',
        'script.products_type_intro' => 'اختارت نوع منتج معين — الجملة اللي قبل الكروت',
        'script.product_lookup_slow' => 'المتجر بطيء وهو بيدوّر على منتج من لينك',
        'script.flow_return_policy_short' => 'أول فلو المرتجع/الاستبدال — توضيح السياسة',
        'script.flow_photo_received' => 'العميلة بعتت صورة جوه فلو',
        'script.flow_return_recorded' => 'اتسجل طلب مرتجع/استبدال',
        'script.flow_complaint_recorded' => 'اتسجلت شكوى',
        'script.flow_cancel_recorded' => 'اتسجل طلب إلغاء/تعديل',
        'script.flow_offer_human' => 'البوت بيعرض موظف',
        'script.flow_case_exists' => 'عندها طلب متسجل من أقل من 24 ساعة',
        'script.flow_retry' => 'إجابتها على خطوة في فلو مش مفهومة (أول مرة)',
        'script.flow_not_understood' => 'إجابتها مش مفهومة (تاني مرة)',
        'script.flow_back_to' => 'سألت سؤال في نص فلو — بعد الإجابة بيرجعها للخطوة',
        'script.flow_too_many_detours' => 'سألت أسئلة كتير في نص فلو',
        'script.flow_switch_offer' => 'طلبت فلو تاني وهي جوه فلو',
        'script.flow_thanks' => 'قالت شكرًا في نص فلو',
        'script.flow_resume_offer' => 'رجعت بعد انقطاع طويل وفيه فلو مفتوح',
        'script.flow_stale_tap' => 'ضغطت زرار من خطوة قديمة',
        'script.flow_menu_fallback' => 'مفيش إجابة ومفيش فلو — بيعرض القائمة',
        'script.flow_not_found_order' => 'الأوردر مش موجود بالبيانات اللي بعتتها',
        'script.handover_ask_topic' => 'طلبت موظف — البوت بيسأل عن الموضوع الأول',
        'script.handover_in_hours' => 'تحويل لموظف في مواعيد العمل',
        'script.handover_after_hours' => 'تحويل لموظف برّه مواعيد العمل',
        'script.handover_no_hours' => 'تحويل لموظف (مواعيد العمل مش متحددة)',
        'script.handover_ack' => 'تحويل لموظف — رسالة التأكيد',
        'script.waiting_ack_' => 'بتكتب وهي مستنية الموظف',
        'script.delayed_response' => 'كررت سؤالها ومحدش رد',
        'script.offer_human' => 'آخر أول رد — عرض موظف',
    ];

    /** The same moments in English (the dashboard is fully English in EN). */
    private const WHEN_EN = [
        'script.greeting_mirror_' => 'She opened with this greeting — the bot greets back first',
        'script.greeting' => 'First reply of the conversation, after the greeting (before the menu)',
        'script.thanks' => 'She said thanks',
        'script.products_intro' => 'She tapped “Models & prices” — the line before the product cards',
        'script.products_type_intro' => 'She picked a product type — the line before the cards',
        'script.product_lookup_slow' => 'The store is slow while a product link is looked up',
        'script.flow_return_policy_short' => 'Start of the return/exchange flow — the policy note',
        'script.flow_photo_received' => 'She sent a photo inside a flow',
        'script.flow_return_recorded' => 'A return/exchange request was recorded',
        'script.flow_complaint_recorded' => 'A complaint was recorded',
        'script.flow_cancel_recorded' => 'A cancel/edit request was recorded',
        'script.flow_offer_human' => 'The bot offers a person',
        'script.flow_case_exists' => 'She already has a request younger than 24 hours',
        'script.flow_retry' => 'Her answer to a flow step was not understood (first time)',
        'script.flow_not_understood' => 'Her answer was not understood (second time)',
        'script.flow_back_to' => 'She asked a question mid-flow — after the answer she is brought back',
        'script.flow_too_many_detours' => 'She asked many questions mid-flow',
        'script.flow_switch_offer' => 'She asked for another flow while one is running',
        'script.flow_thanks' => 'She said thanks mid-flow',
        'script.flow_resume_offer' => 'She came back after a long silence with a flow open',
        'script.flow_stale_tap' => 'She tapped a button of an older step',
        'script.flow_menu_fallback' => 'No answer and no flow — the menu is offered',
        'script.flow_not_found_order' => 'No order matches what she sent',
        'script.handover_ask_topic' => 'She asked for a person — the bot asks the topic first',
        'script.handover_in_hours' => 'Handover during working hours',
        'script.handover_after_hours' => 'Handover outside working hours',
        'script.handover_no_hours' => 'Handover (no working hours set)',
        'script.handover_ack' => 'Handover — the confirmation message',
        'script.waiting_ack_' => 'She keeps writing while waiting for a person',
        'script.delayed_response' => 'She repeated her question and nobody answered',
        'script.offer_human' => 'End of the first reply — offer of a person',
    ];

    /** @return array{sections: list<array{key:string, rows:list<array<string, mixed>>}>, agent: array<string, mixed>} */
    public function build(): array
    {
        $entries = BotKnowledgeEntry::query()->orderBy('sort')->orderBy('id')->get();
        $intents = BotIntent::query()->orderBy('sort')->get();
        $rows = collect();
        $used = [];

        // 1. Questions: what she asks (the intent and its words) → the texts behind it.
        foreach ($intents as $intent) {
            foreach ((array) $intent->script_keys as $scriptKey) {
                $entry = $entries->firstWhere('key', 'script.'.$scriptKey);

                if ($entry === null) {
                    continue;
                }

                $used[$entry->key] = true;
                $words = collect((array) $intent->keywords)->take(6)->implode('، ');
                $rows->push($this->entryRow($entry, 'questions', $this->l('بتسأل عن: '.$intent->label_ar, 'She asks about: '.($intent->label_en ?: $intent->label_ar)).($words !== '' ? $this->l(' — زي: ', ' — e.g. ').$words : ''), (bool) $intent->is_active && (bool) $entry->is_active));
            }
        }

        // 2. Wording scripts with a known moment, then everything else as facts.
        foreach ($entries as $entry) {
            if (isset($used[$entry->key]) || $entry->key === StoreAgent::INSTRUCTIONS_KEY) {
                continue;
            }

            $when = $this->when((string) $entry->key);

            // Eight mirrors share one moment: the title says which greeting each one answers.
            if ($when !== null && str_starts_with((string) $entry->key, 'script.greeting_mirror_')) {
                $when = $entry->title;
            }
            $rows->push($this->entryRow($entry, $when === null ? 'facts' : $this->sectionOf((string) $entry->key), $when ?? $this->l('معلومة بيجاوب منها الـ Agent: ', 'A fact the agent answers from: ').$entry->title, (bool) $entry->is_active));
        }

        // 3. The guided flows, step by step.
        foreach (BotFlow::query()->where('is_active', true)->orderBy('id')->get() as $flow) {
            $definition = is_array($flow->definition) ? $flow->definition : (json_decode((string) $flow->definition, true) ?: []);

            foreach ((array) ($definition['steps'] ?? []) as $stepKey => $step) {
                if (! is_array($step) || ! filled($step['text'] ?? null)) {
                    continue;
                }

                $rows->push([
                    'id' => "flow:{$flow->key}:{$stepKey}",
                    'section' => 'flows',
                    'when' => $this->l('فلو «'.FlowLabels::of((string) $flow->key).'» — خطوة ', 'Flow “'.$flow->key.'” — step ').$stepKey,
                    'reply' => (string) $step['text'],
                    'buttons' => collect((array) ($step['options'] ?? []))->pluck('title')->filter()->values()->all(),
                    'source' => 'flow',
                    'entry_id' => null,
                    'key' => (string) $flow->key,
                    'active' => true,
                    'edit_url' => '/settings/bot-flows/'.$flow->id,
                ]);
            }
        }

        // 4. The owner's keyword rules: they answer before the agent does.
        foreach (BotRule::query()->orderBy('priority')->get() as $rule) {
            $reply = trim((string) ($rule->private_reply ?: collect((array) $rule->public_replies)->first()));

            if ($reply === '') {
                continue;
            }

            $rows->push([
                'id' => 'rule:'.$rule->id,
                'section' => 'rules',
                'when' => $this->l('قاعدة «'.$rule->name.'» — الكلمات: ', 'Rule “'.$rule->name.'” — keywords: ').collect((array) $rule->keywords)->take(8)->implode('، '),
                'reply' => $reply,
                'buttons' => [],
                'source' => 'rule',
                'entry_id' => null,
                'key' => 'rule',
                'active' => (bool) $rule->is_active,
                'edit_url' => '/settings/bot',
            ]);
        }

        // 5. The sentences written in code (steps, handover, status words): shown with the owner's
        //    override when she rewrote one (ArabicOverrides, 2026-09-24).
        foreach (TranslationSources::all() as $source) {
            [$class, $constant] = array_pad(explode('::', $source['context'], 2), 2, '');

            if ($constant === '' || ! isset(self::STEP_LABELS[$class])) {
                continue;
            }

            // Keyed by the masked source, like the override rows themselves.
            $masked = $this->mask->mask($source['text'])[0];
            $override = $this->overrides->display($source['text']);
            $rows->push([
                'id' => 'text:'.md5($masked),
                'section' => in_array($class, ['OrderStatusText', 'DeliveryEstimate'], true) ? 'status_words' : 'steps',
                'when' => $this->l(self::STEP_LABELS[$class][0], self::STEP_LABELS[$class][1]).' — '.$this->constantHint($constant),
                'reply' => $override ?? $source['text'],
                'original' => $override !== null ? $source['text'] : null,
                'buttons' => [],
                'source' => 'text',
                'entry_id' => null,
                'key' => $masked,
                'raw' => $source['text'],
                'active' => true,
                'edit_url' => null,
            ]);
        }

        $instructions = $entries->firstWhere('key', StoreAgent::INSTRUCTIONS_KEY);

        return [
            'agent' => [
                'enabled' => StoreAgent::enabled(),
                'model' => (string) config('crm.bot.agent.model'),
                'entry' => $instructions?->only(['id', 'key', 'title', 'body', 'is_active']),
            ],
            'sections' => collect(self::SECTIONS)
                ->map(fn (string $key) => ['key' => $key, 'rows' => $rows->where('section', $key)->values()->all()])
                ->filter(fn (array $s) => $s['rows'] !== [])
                ->values()->all(),
        ];
    }

    private function entryRow(BotKnowledgeEntry $entry, string $section, string $when, bool $active): array
    {
        return [
            'id' => 'entry:'.$entry->id.':'.$section.':'.md5($when),
            'section' => $section,
            'when' => $when,
            'reply' => (string) $entry->body,
            'buttons' => [],
            'source' => 'entry',
            'entry_id' => $entry->id,
            'key' => (string) $entry->key,
            'active' => $active,
            'edit_url' => null,
        ];
    }

    private function when(string $key): ?string
    {
        foreach (self::WHEN as $prefix => $when) {
            if ($key === $prefix || (str_ends_with($prefix, '_') && str_starts_with($key, $prefix))) {
                return $this->l($when, self::WHEN_EN[$prefix] ?? $when);
            }
        }

        return null;
    }

    /** «ASK_BOTH_TEXT» → «ask both text»: readable enough next to the sentence itself. */
    private function constantHint(string $constant): string
    {
        return mb_strtolower(str_replace('_', ' ', preg_replace('/_(TEXT|BUTTON|QUESTION|LINES?|LABELS?)$/', '', $constant) ?? $constant));
    }

    /** The dashboard language: Arabic wording in ع, English in EN. */
    private function l(string $ar, string $en): string
    {
        return app()->getLocale() === 'en' ? $en : $ar;
    }

    private function sectionOf(string $key): string
    {
        return match (true) {
            str_starts_with($key, 'script.greeting'), $key === 'script.thanks' => 'greeting',
            str_starts_with($key, 'script.product') => 'products',
            str_starts_with($key, 'script.flow_') => 'flow_sentences',
            default => 'handover',
        };
    }

    /** @return Collection<int, array<string, mixed>> every row, flat (tests, exports) */
    public function rows(): Collection
    {
        return collect($this->build()['sections'])->flatMap(fn (array $s) => $s['rows']);
    }
}
