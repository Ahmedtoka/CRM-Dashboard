<?php

namespace App\Inbox;

use App\Analytics\ActivityLogger;
use App\Bot\Learning\Jobs\ReviewConversation;
use App\Enums\ActorType;
use App\Enums\ConversationStatus;
use App\Events\ConversationUpdated;
use App\Models\BotRun;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\ConversationParticipant;
use App\Models\MessageAttachment;
use App\Models\SupportCase;
use App\Models\User;
use App\Support\SafeBroadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Small human-driven conversation state changes shared by the web and API
 * controllers: resolve, reopen, mark read, internal notes, tags.
 */
class ConversationActions
{
    public function __construct(private readonly ActivityLogger $logger, private readonly UserNotifier $notifier) {}

    public function resolve(Conversation $c, User $u): Conversation
    {
        // A resolved conversation has nobody "replying", whoever held the soft lock.
        // The bot handover routing (priority_level/queue/handover_category) is done once
        // resolved (Task 4 ruling 3); a human reply along the way does not clear it.
        $c->forceFill([
            'status' => ConversationStatus::Resolved,
            'resolved_at' => now(),
            'resolved_by_id' => $u->id,
            'locked_by_id' => null,
            'locked_until' => null,
            'priority_level' => null,
            'queue' => null,
            'handover_category' => null,
            // Task 5 ruling 6a: forget the flow's memory (clarified/repeat_count/asks…)
            // so a returning customer is not handed over as "repeated" or "unclear".
            'bot_state' => $c->resetBotState(),
        ])->save();

        $this->logger->log(ActorType::User, $u, ActivityLogger::CONVERSATION_RESOLVED, null, $c);

        SafeBroadcast::send(new ConversationUpdated($c));

        // Learning v2 §2: a finished real conversation gets a delayed review.
        ReviewConversation::dispatchFor($c);

        return $c;
    }

    public function reopen(Conversation $c, User $u): Conversation
    {
        $c->forceFill([
            'status' => ConversationStatus::Open,
            'resolved_at' => null,
            'resolved_by_id' => null,
        ])->save();

        $this->logger->log(ActorType::User, $u, ActivityLogger::CONVERSATION_REOPENED, null, $c);

        SafeBroadcast::send(new ConversationUpdated($c));

        return $c;
    }

    /**
     * Wipes a conversation back to "first contact" for bot testing: messages (and their
     * attachment files), notes, participants, tags, bot runs and support cases ("الطلبات")
     * are deleted, and every
     * handover / bot-flow / response-time field is cleared. The row and its customer link
     * stay, so the customer's next message lands here and the bot greets them as new.
     * Activity logs are kept for the audit trail.
     */
    public function reset(Conversation $c, User $u): Conversation
    {
        $files = MessageAttachment::query()
            ->whereIn('message_id', $c->messages()->select('id'))
            ->whereNotNull('path')
            ->get(['disk', 'path']);

        DB::transaction(function () use ($c) {
            $c->messages()->delete();
            $c->notes()->delete();
            ConversationParticipant::where('conversation_id', $c->id)->delete();
            BotRun::where('conversation_id', $c->id)->delete();
            SupportCase::where('conversation_id', $c->id)->delete();
            $c->tags()->detach();

            $c->forceFill([
                'status' => ConversationStatus::Open,
                'priority' => 'normal',
                'handler' => 'bot',
                'needs_human' => false,
                'first_responder_id' => null,
                'last_responder_id' => null,
                'locked_by_id' => null,
                'locked_until' => null,
                'claimed_until' => null,
                'unread_count' => 0,
                'last_message_at' => null,
                'last_customer_message_at' => null,
                'first_response_at' => null,
                'handover_at' => null,
                'resolved_at' => null,
                'resolved_by_id' => null,
                'bot_due_at' => null,
                'bot_state' => null,
                'priority_level' => null,
                'handover_category' => null,
                'queue' => null,
            ])->save();
        });

        foreach ($files as $file) {
            Storage::disk($file->disk ?: 'media')->delete((string) $file->path);
        }

        $this->logger->log(ActorType::User, $u, ActivityLogger::CONVERSATION_RESET, null, $c);

        SafeBroadcast::send(new ConversationUpdated($c));

        return $c;
    }

    public function markRead(Conversation $c): Conversation
    {
        if ((int) $c->unread_count !== 0) {
            $c->forceFill(['unread_count' => 0])->save();

            SafeBroadcast::send(new ConversationUpdated($c));
        }

        return $c;
    }

    /**
     * @param  array<int, int>  $mentionIds
     */
    public function addNote(Conversation $c, User $u, string $body, array $mentionIds = []): ConversationNote
    {
        // Silently drop ids that aren't real, active users who can access this
        // conversation's platform, and never notify the note's own author.
        $mentioned = User::query()->whereIn('id', array_unique(array_map('intval', $mentionIds)))->whereKeyNot($u->id)
            ->where('is_active', true)->with('userPlatforms')->get()
            ->filter(fn (User $m) => $m->canAccessPlatform($c->platform))->values();

        $note = $c->notes()->create(['user_id' => $u->id, 'body' => $body, 'mentions' => $mentioned->pluck('id')->all()]);
        $note->setRelation('user', $u);

        $this->logger->log(ActorType::User, $u, ActivityLogger::NOTE_ADDED, $note, $c);

        foreach ($mentioned as $m) {
            $this->notifier->notify($m, 'note.mention', [
                'conversation_id' => $c->id,
                'note_id' => $note->id,
                'by' => $u->name,
                // Controller ruling 3 caps this at 80 chars total (task-14's own privacy
                // rule for notification previews) — tighter than the brief's own 120-char
                // pseudocode. `Str::limit()`'s $limit argument is the length of the
                // untruncated portion, not the final string — it appends a 3-char "..."
                // on top when it truncates, so passing 80 could yield an 83-char result.
                // 77 keeps the final string at exactly <= 80 in every case.
                // Fix round 1, ruling 2: phone numbers are masked BEFORE truncation, so a
                // long number never survives partially inside the 80-char preview either.
                'excerpt' => Str::limit(self::maskPhones($body), 77),
            ]);
        }

        return $note;
    }

    /**
     * Masks any run of 7+ digits (ASCII or Arabic-Indic), tolerating `+`, spaces
     * and dashes interspersed — e.g. "01001234567" or "+20 100 123 4567" both
     * become "•••" — so a phone number pasted into a note body never reaches a
     * mentioned teammate's notification (fix round 1, ruling 2).
     */
    private static function maskPhones(string $text): string
    {
        // Anchored on a digit/`+` at both ends so a run's surrounding spaces are
        // never swallowed into the mask (only the digits-and-separators core is).
        return preg_replace_callback('/[+0-9\x{0660}-\x{0669}][+\-\s0-9\x{0660}-\x{0669}]*[0-9\x{0660}-\x{0669}]/u', function (array $m) {
            $digitCount = preg_match_all('/[0-9\x{0660}-\x{0669}]/u', $m[0]);

            return $digitCount >= 7 ? '•••' : $m[0];
        }, $text);
    }

    /**
     * @param  array<int, int>  $tagIds
     */
    public function syncTags(Conversation $c, array $tagIds): Conversation
    {
        $c->tags()->sync($tagIds);
        $c->load('tags');

        SafeBroadcast::send(new ConversationUpdated($c));

        return $c;
    }
}
