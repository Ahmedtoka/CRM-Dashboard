<?php

namespace App\Bot\Learning;

use App\Bot\Flows\FlowState;
use App\Enums\SenderType;
use App\Models\BotIntent;
use App\Models\BotRun;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Compact transcripts for learning (design §6, learning v2 §2), rendered as
 * `عميل:` / `بوت:` / `موظف:` lines with the intent keys the bot recorded:
 * one Cairo day (busiest first) or one conversation for its review. Real
 * conversations only — every query goes through `LearningScope`.
 */
final class TranscriptBuilder
{
    public const TZ = 'Africa/Cairo';

    /** A line written by a human agent — how a handover is counted. */
    public const AGENT_PREFIX = 'موظف: ';

    /** Long messages are cut so 60 conversations still fit one prompt. */
    private const MAX_BODY = 300;

    /**
     * Three ceilings keep one busy day from blowing up the nightly bill or the
     * 60 s timeout: at most 60 conversations, at most 40 lines each (the newest
     * kept, since the end of a chat is where the bot failed or an agent took
     * over), and a total character budget over all of them.
     */
    private const MAX_LINES = 40;

    /** Learning v2 §2: a single-conversation review sees at most 60 lines, 6 of them earlier context. */
    private const MAX_REVIEW_LINES = 60;

    private const CONTEXT_LINES = 6;

    public const MAX_CHARS = 60000;

    private const ROLE_PREFIXES = [
        'customer' => 'عميل',
        'bot' => 'بوت',
        'user' => 'موظف',
    ];

    /**
     * @return list<array{conversation_id:int, lines: list<string>, intents: list<string>}>
     */
    public function forDate(CarbonImmutable $date, int $max = 60, int $charBudget = self::MAX_CHARS): array
    {
        [$from, $to] = $this->window($date);

        $conversationIds = $this->conversationIdsForDate($date, $max);

        if ($conversationIds === []) {
            return [];
        }

        $messages = Message::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->whereIn('sender_type', array_keys(self::ROLE_PREFIXES))
            ->orderBy('conversation_id')
            ->orderBy('id')
            ->get(['id', 'conversation_id', 'sender_type', 'body', 'attachments'])
            ->groupBy('conversation_id');

        $intents = BotRun::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->whereNotNull('intent')
            ->orderBy('id')
            ->get(['conversation_id', 'intent'])
            ->groupBy('conversation_id');

        $transcripts = [];

        foreach ($conversationIds as $id) {
            $lines = [];

            foreach ($messages->get($id, collect()) as $message) {
                $line = $this->line($message);

                if ($line !== null) {
                    $lines[] = $line;
                }
            }

            if ($lines === []) {
                continue;
            }

            $transcripts[] = [
                'conversation_id' => (int) $id,
                // The tail of a chat is the interesting part, so a long one loses its opening.
                'lines' => count($lines) > self::MAX_LINES ? array_slice($lines, -self::MAX_LINES) : $lines,
                'intents' => array_values(array_unique($intents->get($id, collect())->pluck('intent')->all())),
            ];
        }

        return $this->withinBudget($transcripts, $charBudget);
    }

    /**
     * The day's real conversations (learning v2 §1: `LearningScope` only),
     * busiest first. The nightly backfill reviews from this list.
     *
     * @return list<int>
     */
    public function conversationIdsForDate(CarbonImmutable $date, int $max = 60): array
    {
        [$from, $to] = $this->window($date);

        return Message::query()
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->whereIn('sender_type', array_keys(self::ROLE_PREFIXES))
            ->whereIn('conversation_id', LearningScope::conversations()->select('id'))
            ->groupBy('conversation_id')
            ->orderByDesc(DB::raw('count(*)'))
            ->orderBy('conversation_id')
            ->limit($max)
            ->pluck('conversation_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * One conversation for the per-conversation review (learning v2 §2): the
     * messages after `$afterMessageId` (the previous review's last one), plus
     * the 6 before them for context, newest 60 lines at most. `flows` lists the
     * guided flows the matched intents start and the flow step the
     * conversation is in now, as `flow_key` / `flow_key.step_id`.
     *
     * @return array{conversation_id:int, lines: list<string>, intents: list<string>, flows: list<string>}
     */
    public function forConversation(Conversation $conversation, ?int $afterMessageId = null): array
    {
        $roles = array_keys(self::ROLE_PREFIXES);
        $columns = ['id', 'conversation_id', 'sender_type', 'body', 'attachments', 'created_at'];

        $new = Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('sender_type', $roles)
            ->when($afterMessageId !== null, fn ($q) => $q->where('id', '>', $afterMessageId))
            ->orderBy('id')
            ->get($columns);

        $context = $afterMessageId === null ? collect() : Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('sender_type', $roles)
            ->where('id', '<=', $afterMessageId)
            ->orderByDesc('id')
            ->limit(self::CONTEXT_LINES)
            ->get($columns)
            ->reverse();

        $lines = [];

        foreach ($context->concat($new) as $message) {
            $line = $this->line($message);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        if (count($lines) > self::MAX_REVIEW_LINES) {
            $lines = array_slice($lines, -self::MAX_REVIEW_LINES);
        }

        $since = $new->first()?->created_at;

        $intents = BotRun::query()
            ->where('conversation_id', $conversation->id)
            ->when($afterMessageId !== null && $since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('intent')
            ->orderBy('id')
            ->pluck('intent')
            ->unique()
            ->values()
            ->all();

        $flows = $intents === [] ? [] : BotIntent::query()
            ->whereIn('key', $intents)
            ->whereNotNull('flow_key')
            ->pluck('flow_key')
            ->all();

        $current = FlowState::flow($conversation);

        if ($current !== null) {
            $flows[] = $current['step'] !== '' ? "{$current['key']}.{$current['step']}" : $current['key'];
        }

        return [
            'conversation_id' => (int) $conversation->id,
            'lines' => array_values($lines),
            'intents' => array_values(array_map('strval', $intents)),
            'flows' => array_values(array_unique(array_map('strval', $flows))),
        ];
    }

    /**
     * Keeps the prompt under `$charBudget` characters. `$transcripts` is
     * already ordered busiest first, so the smallest conversations are the
     * ones dropped; the busiest one is always kept, whatever the budget.
     *
     * @param  list<array{conversation_id:int, lines: list<string>, intents: list<string>}>  $transcripts
     * @return list<array{conversation_id:int, lines: list<string>, intents: list<string>}>
     */
    private function withinBudget(array $transcripts, int $charBudget): array
    {
        $kept = [];
        $used = 0;

        foreach ($transcripts as $transcript) {
            $size = mb_strlen(implode("\n", $transcript['lines']));

            if ($kept !== [] && $used + $size > $charBudget) {
                break;
            }

            $kept[] = $transcript;
            $used += $size;
        }

        return $kept;
    }

    /**
     * The Cairo day as a half-open UTC `created_at` range, so no message near
     * midnight is lost. Public because the command counts that day's support
     * cases over exactly the same window.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(CarbonImmutable $date): array
    {
        $start = $date->setTimezone(self::TZ)->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }

    private function line(Message $message): ?string
    {
        $role = $message->sender_type instanceof SenderType ? $message->sender_type->value : (string) $message->sender_type;
        $prefix = self::ROLE_PREFIXES[$role] ?? null;

        if ($prefix === null) {
            return null;
        }

        $body = trim((string) $message->body);

        if (mb_strlen($body) > self::MAX_BODY) {
            $body = mb_substr($body, 0, self::MAX_BODY).'…';
        }

        if (is_array($message->attachments) && $message->attachments !== []) {
            $body = trim($body.' [صورة]');
        }

        return $body === '' ? null : "{$prefix}: {$body}";
    }
}
