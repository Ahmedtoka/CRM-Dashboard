<?php

namespace App\Bot\Flows;

use App\Bot\Flows\Jobs\HandoverTopicTimeout;
use App\Bot\Flows\Sandbox\SandboxMode;
use App\Bot\WorkingHours;
use App\Enums\AttachmentType;
use App\Enums\Handler;
use App\Events\ConversationUpdated;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\BotIntent;
use App\Models\BotKnowledgeEntry;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Message;
use App\Support\SafeBroadcast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * «كلم موظف» (the owner's flow 7, 2026-09-19). When she asks for a person (the menu
 * option, or in words) the bot first asks what she needs:
 *
 *   «أكيد 🌸 ممكن تقوليلي باختصار محتاجة إيه؟ …» [حوّليني على طول]
 *
 * Her next message (text or a photo) is the topic: it is kept as an internal note
 * «موضوع التحويل: …» and as `conversations.handover_topic` (the inbox shows it as the
 * handover reason). «حوّليني على طول» / «مش مهم» skips it, and no answer within
 * TOPIC_WAIT_SECONDS hands her over anyway. Every handover then gets one reply that
 * depends on the working hours (bot_settings.working_hours, Africa/Cairo):
 *
 *   in hours      «تمام ✅ حولتك لحد من الفريق، هيرد عليكي خلال دقايق 🌸»
 *   after hours   «تمام ✅ سجلت طلبك، وفريق خدمة العملاء هيرد عليكي أول ما نفتح {next_opening} 🌸»
 *   no hours set  «تمام ✅ حولتك لحد من الفريق، هيرد عليكي في أقرب وقت 🌸»
 *
 * Handovers the bot decides itself (a failed verification, a flow's handover step, the
 * retry offer…) skip the question and only send that reply. All texts are editable
 * scripts; a script the owner turned off is not sent (an off question = no question).
 * The pending question lives in `bot_state.handover_topic` = {asked_at}.
 */
class HumanHandover
{
    public const STATE_KEY = 'handover_topic';

    public const SKIP_PAYLOAD = 'handover:now';

    public const SKIP_TITLE = 'حوّليني على طول';

    public const NOTE_PREFIX = 'موضوع التحويل: ';

    public const PHOTO_TOPIC = '📷 صورة';

    /** How long the bot waits for the topic before handing over anyway. */
    public const TOPIC_WAIT_SECONDS = 120;

    /** Replies that skip the question (compared cleaned, as the whole reply). */
    private const SKIP_WORDS = ['مش مهم', 'مش مهم خالص', 'حوليني', 'حوليني على طول', 'على طول', 'حولني', 'حولني على طول', 'لا', 'لاء', 'مفيش'];

    public function __construct(
        private readonly OutboundService $outbound,
        private readonly FlowHandover $handovers,
        private readonly FlowPrompter $prompter,
        private readonly FlowAnswerResolver $resolver,
    ) {}

    public static function pending(Conversation $c): bool
    {
        return is_array(($c->bot_state ?? [])[self::STATE_KEY] ?? null);
    }

    /** She asked for a person: the topic question, or straight to the team when the question is turned off. */
    public function askTopic(Conversation $c, ?string $customerText = null, int $delayMs = 0): void
    {
        FlowState::clear($c);
        $question = $this->text('handover_ask_topic');

        if ($question === null) {
            $this->handover($c, null, $customerText);

            return;
        }

        $askedAt = now()->toIso8601String();
        $c->forceFill(['bot_state' => array_merge($c->bot_state ?? [], [self::STATE_KEY => ['asked_at' => $askedAt]])])->save();
        $this->send($c, $question, [['title' => self::SKIP_TITLE, 'payload' => self::SKIP_PAYLOAD]], $delayMs);

        if (! SandboxMode::active()) {
            HandoverTopicTimeout::dispatch($c->id, $askedAt)->delay(now()->addSeconds(self::TOPIC_WAIT_SECONDS));
        }
    }

    /**
     * She asked for a person in words ("عايزة أكلم موظف"). Waiting for the topic: this is it.
     * Inside a flow (not the main menu): straight to the team, the flow's data as context.
     * A request that already says what it is about ("عايزة اكلم حد عشان الأوردر اتأخر") is
     * its own topic. Otherwise the topic question.
     */
    public function requested(Conversation $c, Collection $burst, string $text): void
    {
        if (self::pending($c)) {
            $this->answer($c, $burst);

            return;
        }

        $flow = FlowState::flow($c);

        if ($flow !== null && $flow['key'] !== ConversationRouter::MAIN_MENU) {
            $this->handover($c, null, $text, 'keyword', 'human_request', $this->prompter->summaryLines($flow['data']));

            return;
        }

        if ($this->saysWhy($text)) {
            $this->handover($c, $text, $text, 'keyword');

            return;
        }

        $this->askTopic($c, $text);
    }

    /** The human_request intent's words (when that intent is on): "خدمة العملاء", "اكلم حد"… */
    public function isHumanRequest(string $text): bool
    {
        $intent = BotIntent::query()->where('key', 'human_request')->where('is_active', true)->first();
        $clean = $this->resolver->clean($text);

        if ($intent === null || $clean === '') {
            return false;
        }

        foreach ((array) $intent->keywords as $keyword) {
            $k = $this->resolver->clean((string) $keyword);

            if ($k !== '' && str_contains($clean, $k)) {
                return true;
            }
        }

        return false;
    }

    /** More than the request itself: at least two words left once the asking words are gone. */
    private function saysWhy(string $text): bool
    {
        $asking = array_map(fn (string $w) => $this->resolver->clean($w), self::ASKING_WORDS);
        $words = array_filter(explode(' ', $this->resolver->clean($text)), fn (string $w) => $w !== '' && ! in_array($w, $asking, true));

        return count($words) >= 2;
    }

    /** Words of a bare "I want a person" (cleaned: ة→ه, أ→ا). */
    private const ASKING_WORDS = [
        'عايزه', 'عايز', 'عاوزه', 'عاوز', 'ممكن', 'اكلم', 'اتكلم', 'مع', 'حد', 'موظف', 'موظفه', 'خدمه', 'العملاء', 'الدعم', 'لو', 'سمحت',
        'من', 'فضلك', 'بشري', 'انسان', 'يكلمني', 'يرد', 'عليا', 'حد', 'اكلمه', 'اتواصل', 'معاه', 'رقم', 'واتس', 'واتساب', 'customer', 'service',
        'please', 'يا', 'جماعه', 'انا', 'عايزين', 'الفريق', 'فريق', 'حضرتك', 'اكلمكم', 'معاكم', 'مسؤول', 'المسؤول', 'مدير', 'المدير', 'الموظف', 'الموظفين',
    ];

    /** Her reply to the topic question: the topic, or a skip. */
    public function answer(Conversation $c, Collection $burst, ?string $payload = null): void
    {
        $texts = $burst->map(fn (Message $m) => trim((string) $m->body))->filter()->values()->all();
        $text = implode("\n", $texts);

        if (in_array($payload, [self::SKIP_PAYLOAD, 'handover'], true) || ($payload === null && $this->isSkip($text))) {
            $this->handover($c, null, $text !== '' ? $text : null);

            return;
        }

        $topic = $text;

        if ($burst->contains(fn (Message $m) => $this->hasImage($m))) {
            $topic = self::PHOTO_TOPIC.($topic !== '' ? ' — '.$topic : '');
        }

        $this->handover($c, $topic !== '' ? $topic : null, $text !== '' ? $text : null);
    }

    /** No answer in time: hand over without a topic (only for the question the job was queued for). */
    public function timeout(Conversation $c, string $askedAt): void
    {
        $pending = ($c->bot_state ?? [])[self::STATE_KEY] ?? null;

        if ($c->handler !== Handler::Bot || ! is_array($pending) || ($pending['asked_at'] ?? null) !== $askedAt) {
            return;
        }

        $this->handover($c, null, null);
    }

    /**
     * Hands her to the team, the working-hours reply first. `$topic` (her own words) becomes the
     * «موضوع التحويل» note and the inbox's handover reason; null for handovers the bot decided.
     *
     * @param  list<string>  $summaryExtra  extra lines for the handover note (a flow's collected data)
     */
    public function handover(Conversation $c, ?string $topic, ?string $customerText, string $reason = 'human_request', string $category = 'human_request', array $summaryExtra = []): void
    {
        $this->clearPending($c);

        if (($reply = $this->hoursReply()) !== null) {
            $this->send($c, $reply);
        }

        $topic = $topic !== null && trim($topic) !== '' ? Str::limit(trim($topic), 240, '…') : null;

        if ($topic !== null) {
            $c->forceFill(['handover_topic' => $topic])->save();
        }

        $this->handovers->handover($c, $reason, $customerText, [
            'priority' => 'medium',
            'queue' => 'agents',
            'category' => $category,
            'summary_extra' => $summaryExtra,
        ]);

        if ($topic !== null) {
            ConversationNote::create(['conversation_id' => $c->id, 'user_id' => null, 'body' => self::NOTE_PREFIX.$topic, 'mentions' => []]);
            SafeBroadcast::send(new ConversationUpdated($c));
        }
    }

    /** The working-hours aware reply (null when that script is turned off). */
    public function hoursReply(?CarbonImmutable $at = null): ?string
    {
        $settings = BotSetting::current();

        if (! WorkingHours::configured($settings)) {
            return $this->text('handover_no_hours');
        }

        if (WorkingHours::isOpen($settings, $at)) {
            return $this->text('handover_in_hours');
        }

        $opening = WorkingHours::nextOpening($settings, $at);
        $text = $opening !== null ? $this->text('handover_after_hours') : $this->text('handover_no_hours');

        return $text !== null && $opening !== null ? str_replace('{next_opening}', WorkingHours::phrase($opening, $at), $text) : $text;
    }

    public function isSkip(string $text): bool
    {
        $clean = $this->resolver->clean($text);

        return $clean !== '' && in_array($clean, array_map(fn ($w) => $this->resolver->clean($w), self::SKIP_WORDS), true);
    }

    private function clearPending(Conversation $c): void
    {
        $state = $c->bot_state ?? [];

        if (array_key_exists(self::STATE_KEY, $state)) {
            unset($state[self::STATE_KEY]);
            $c->forceFill(['bot_state' => $state !== [] ? $state : null])->save();
        }

        FlowState::clear($c);
    }

    /** The active script, its seeded text when the row does not exist, null when the owner turned it off. */
    private function text(string $key): ?string
    {
        $body = $this->prompter->script($key);

        if ($body !== null) {
            return $body;
        }

        if (BotKnowledgeEntry::query()->where('key', 'script.'.$key)->exists()) {
            return null;
        }

        $seeded = trim((string) (FlowScripts::all()[$key]['body'] ?? ''));

        return $seeded !== '' ? $seeded : null;
    }

    private function hasImage(Message $m): bool
    {
        if ($m->exists && $m->mediaAttachments()->where('type', AttachmentType::Image->value)->exists()) {
            return true;
        }

        return collect((array) $m->attachments)->contains(fn ($a) => is_array($a) && ($a['type'] ?? null) === 'image');
    }

    /** @param  list<array{title:string, payload:string}>  $buttons */
    private function send(Conversation $c, string $text, array $buttons = [], int $delayMs = 0): void
    {
        try {
            $this->outbound->sendBot($c, $text, $delayMs, false, $buttons);
        } catch (WindowClosedException) {
            Log::info('handover.window_closed', ['conversation_id' => $c->id]);
        }
    }
}
