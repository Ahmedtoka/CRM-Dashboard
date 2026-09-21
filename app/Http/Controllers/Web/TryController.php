<?php

namespace App\Http\Controllers\Web;

use App\Enums\AttachmentStatus;
use App\Enums\AttachmentType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\SenderType;
use App\Http\Controllers\Controller;
use App\Media\InboundMediaFetcher;
use App\Models\BotTestLink;
use App\Models\BotTestSession;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\TestLinks\TestLinkSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The tester's side of a public test link: `GET /try/{token}` (design 2026-09-21 §2).
 *
 * No login, no account, no Inertia — a small Blade shell with its own Vue entry, so
 * the page is light on a phone. The chrome is English (the owner's addition 3); the
 * bot answers in Arabic because its replies come from the published flows.
 *
 * Everything the tester sends goes through the real ingestion and bot pipeline; the
 * replies are read back by polling this controller, and an agent answering from the
 * inbox reaches the same place.
 */
class TryController extends Controller
{
    /** Writes a session may make per minute (design §2): sending, photos, resets. */
    public const PER_MINUTE = 20;

    /** Writes one address may make per minute across sessions (design §6). */
    public const PER_IP_PER_MINUTE = 60;

    /**
     * The 3 s poll alone is 20 requests a minute, so it gets its own, roomier budget:
     * counting it against the write limit throttled a tester who was only sitting there,
     * and three testers on one office connection tripped the address limit.
     */
    public const POLLS_PER_MINUTE = 40;

    public const POLLS_PER_IP_PER_MINUTE = 600;

    /** A page view is counted at most once per this many seconds per browser session. */
    public const VIEW_COOLDOWN_SECONDS = 120;

    public const MAX_TEXT = 1000;

    public function __construct(private readonly TestLinkSessions $sessions) {}

    /** The page itself: the name prompt, or the chat when this browser already started. */
    public function show(Request $request, string $token): View
    {
        $link = $this->link($token);

        if ($link === null || ! $link->isOpen()) {
            return view('try.closed', ['reason' => $link === null ? 'unknown' : ($link->is_active ? 'expired' : 'stopped')]);
        }

        $this->countView($request, $link);

        $session = $this->currentSession($request, $link);

        return view('try.chat', [
            'link' => $link,
            'token' => $link->token,
            'state' => $this->state($link, $session),
        ]);
    }

    /** «What's your name?» — starts the tester's run. */
    public function start(Request $request, string $token): JsonResponse
    {
        $link = $this->openLink($token);
        $this->throttle($request, $link, 'start');

        $data = $request->validate([
            'name' => ['required', 'string', 'min:'.TestLinkSessions::NAME_MIN, 'max:'.TestLinkSessions::NAME_MAX],
        ]);

        $existing = $this->currentSession($request, $link);

        if ($existing !== null && ! $existing->isEnded()) {
            return response()->json($this->state($link, $existing));
        }

        if ($link->isFull()) {
            return response()->json($this->state($link, null) + ['error' => 'full'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $session = $this->sessions->start($link, $data['name'], $request);
        $request->session()->put($this->sessionKey($link), $session->session_token);

        return response()->json($this->state($link, $session));
    }

    /** The tester wrote a line, or tapped a quick reply. */
    public function send(Request $request, string $token): JsonResponse
    {
        $link = $this->openLink($token);
        $session = $this->requireSession($request, $link);
        $this->throttle($request, $link, 'send');

        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:'.self::MAX_TEXT],
            'payload' => ['nullable', 'string', 'max:120'],
        ]);

        $text = trim((string) ($data['text'] ?? ''));

        if ($text === '' && blank($data['payload'] ?? null)) {
            return response()->json(['message' => 'Write something first.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->capReached($link, $session)) {
            return response()->json($this->state($link, $session), Response::HTTP_TOO_MANY_REQUESTS);
        }

        $this->sessions->inbound($session, $text, $data['payload'] ?? null);

        return response()->json($this->state($link, $session->refresh()));
    }

    /** A photo from the phone's gallery or camera, ingested as an inbound image. */
    public function photo(Request $request, string $token): JsonResponse
    {
        $link = $this->openLink($token);
        $session = $this->requireSession($request, $link);
        $this->throttle($request, $link, 'send');

        $request->validate([
            'photo' => ['required', 'file', 'image', 'max:12288'],
            'text' => ['nullable', 'string', 'max:'.self::MAX_TEXT],
        ]);

        if ($this->capReached($link, $session)) {
            return response()->json($this->state($link, $session), Response::HTTP_TOO_MANY_REQUESTS);
        }

        /** @var UploadedFile $file */
        $file = $request->file('photo');
        $path = InboundMediaFetcher::UPLOAD_PREFIX.now()->format('Y/m').'/'.Str::uuid().'.'.($file->guessExtension() ?: 'jpg');
        Storage::disk((string) config('crm.media.disk', 'media'))->put($path, (string) file_get_contents((string) $file->getRealPath()));

        $this->sessions->inbound($session, trim((string) $request->input('text', '')), null, [[
            'type' => 'image',
            'url' => InboundMediaFetcher::UPLOAD_SCHEME.$path,
            'mime_type' => $file->getClientMimeType(),
            'filename' => $file->getClientOriginalName(),
        ]]);

        return response()->json($this->state($link, $session->refresh()));
    }

    /** Polling (every 3s): the bot's and the agents' replies, and whether the bot is typing. */
    public function poll(Request $request, string $token): JsonResponse
    {
        $link = $this->link($token);

        if ($link === null) {
            return response()->json(['open' => false, 'reason' => 'unknown'], Response::HTTP_NOT_FOUND);
        }

        $session = $this->currentSession($request, $link);
        $this->throttle($request, $link, 'poll');

        if ($session !== null && $link->isOpen() && ! $session->isEnded()) {
            BotTestSession::query()->whereKey($session->id)->update(['last_seen_at' => now()]);
        }

        return response()->json($this->state($link, $session, markSeen: true));
    }

    /** «Start over»: this run is kept for the report, a brand-new one begins. */
    public function reset(Request $request, string $token): JsonResponse
    {
        $link = $this->openLink($token);
        $session = $this->requireSession($request, $link);
        $this->throttle($request, $link, 'start');

        if ($link->isFull()) {
            return response()->json($this->state($link, $session) + ['error' => 'full'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $fresh = $this->sessions->start($link, $session->tester_name, $request, previous: $session);
        $request->session()->put($this->sessionKey($link), $fresh->session_token);

        return response()->json($this->state($link, $fresh));
    }

    /** An image inside the tester's own conversation, served only to that tester. */
    public function media(Request $request, string $token, MessageAttachment $attachment): StreamedResponse
    {
        $link = $this->link($token) ?? abort(404);
        $session = $this->requireSession($request, $link);

        abort_unless(
            $attachment->status === AttachmentStatus::Stored
            && $attachment->path !== null
            && $attachment->message_id !== null
            && Message::query()->whereKey($attachment->message_id)->value('conversation_id') === $session->conversation_id,
            404,
        );

        $disk = Storage::disk((string) $attachment->disk);

        abort_unless($disk->exists((string) $attachment->path), 404);

        return $disk->response((string) $attachment->path, null, [
            'Content-Type' => $attachment->mime ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=600',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * Everything the page draws, in one payload.
     *
     * @return array<string, mixed>
     */
    private function state(BotTestLink $link, ?BotTestSession $session, bool $markSeen = false): array
    {
        $open = $link->isOpen();
        $base = [
            'open' => $open,
            'reason' => $open ? null : ($link->is_active ? 'expired' : 'stopped'),
            'label' => $link->label,
            'poll_ms' => 3000,
            'name_min' => TestLinkSessions::NAME_MIN,
            'name_max' => TestLinkSessions::NAME_MAX,
            'max_text' => self::MAX_TEXT,
        ];

        if ($session === null) {
            return $base + ['started' => false, 'session' => null, 'messages' => [], 'typing' => false];
        }

        $conversation = $session->conversation;
        $cap = $link->messageCap();
        $used = (int) $session->messages_count;

        $messages = $conversation === null ? collect() : $conversation->messages()
            ->with('mediaAttachments')
            ->where('sender_type', '!=', SenderType::System->value)
            ->orderBy('id')
            ->limit(400)
            ->get();

        if ($markSeen && $conversation !== null) {
            $this->markDelivered($messages);
        }

        return $base + [
            'started' => true,
            'session' => [
                'token' => $session->session_token,
                'name' => $session->tester_name,
                'run_no' => (int) $session->run_no,
                'ended' => $session->isEnded(),
                'used' => $used,
                'cap' => $cap,
                'cap_reached' => $used >= $cap,
            ],
            'typing' => $conversation !== null
                && $conversation->bot_due_at !== null
                && $conversation->bot_due_at->isFuture(),
            'messages' => $messages->map(fn (Message $m) => $this->message($m, $link))->values()->all(),
        ];
    }

    /**
     * The test driver never reaches a platform, so nothing would ever send a delivery
     * receipt back: the moment the tester's own page has the message in hand is exactly
     * when it is delivered and read, which is what the tick line shows.
     *
     * @param  Collection<int, Message>  $messages
     */
    private function markDelivered(Collection $messages): void
    {
        $ids = $messages
            ->filter(fn (Message $m) => $m->direction === MessageDirection::Out && $m->status === MessageStatus::Sent)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return;
        }

        Message::query()->whereIn('id', $ids)->update([
            'status' => MessageStatus::Read->value,
            'delivered_at' => now(),
            'read_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function message(Message $m, BotTestLink $link): array
    {
        return [
            'id' => $m->id,
            'direction' => $m->direction?->value,
            'sender' => $m->sender_type?->value,
            'body' => $m->body,
            'buttons' => array_values($m->buttons ?? []),
            'cards' => $m->cards,
            'status' => $m->status?->value,
            'created_at' => $m->created_at?->toIso8601String(),
            'images' => $m->mediaAttachments
                ->filter(fn (MessageAttachment $a) => $a->type === AttachmentType::Image && $a->status === AttachmentStatus::Stored)
                ->map(fn (MessageAttachment $a) => [
                    'id' => $a->id,
                    'url' => url("/try/{$link->token}/media/{$a->id}"),
                    'width' => $a->width,
                    'height' => $a->height,
                ])->values()->all(),
        ];
    }

    private function capReached(BotTestLink $link, BotTestSession $session): bool
    {
        if ((int) $session->messages_count < $link->messageCap()) {
            return false;
        }

        $this->sessions->end($session, BotTestSession::ENDED_CAP);

        return true;
    }

    private function link(string $token): ?BotTestLink
    {
        if (! preg_match('/^[a-z0-9]{8,64}$/', $token)) {
            return null;
        }

        return BotTestLink::query()->where('token', $token)->first();
    }

    private function openLink(string $token): BotTestLink
    {
        $link = $this->link($token);

        abort_if($link === null || ! $link->isOpen(), Response::HTTP_GONE, 'This link is closed.');

        return $link;
    }

    private function sessionKey(BotTestLink $link): string
    {
        return "try.{$link->id}";
    }

    /** The run token this browser holds for this link, if any. */
    private function storedToken(Request $request, BotTestLink $link): ?string
    {
        $token = $request->session()->get($this->sessionKey($link));

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** The run this browser owns on this link, if any — never one it was handed. */
    private function currentSession(Request $request, BotTestLink $link): ?BotTestSession
    {
        $token = $this->storedToken($request, $link);

        if ($token === null) {
            return null;
        }

        return BotTestSession::query()
            ->with('conversation')
            ->where('bot_test_link_id', $link->id)
            ->where('session_token', $token)
            ->first();
    }

    /**
     * A tester may only ever act on their own run: the token comes from their own
     * browser session, and a `session` sent with the request has to match it, so a
     * leaked token from another phone reads nothing.
     */
    private function requireSession(Request $request, BotTestLink $link): BotTestSession
    {
        $session = $this->currentSession($request, $link);

        abort_if($session === null, Response::HTTP_FORBIDDEN, 'Start the chat first.');

        $claimed = $request->input('session');

        abort_if(is_string($claimed) && $claimed !== '' && ! hash_equals($session->session_token, $claimed), Response::HTTP_FORBIDDEN, 'Not your session.');

        abort_if($session->isEnded(), Response::HTTP_GONE, 'This run has finished.');

        return $session;
    }

    /** Per session and per address (design §2 and §6); a page view is never throttled away. */
    private function throttle(Request $request, BotTestLink $link, string $action): void
    {
        $polling = $action === 'poll';

        // Keyed on the tester's run once they have one, so the limit follows the person
        // rather than a browser session id that may be rotated underneath them. Polls are
        // counted separately from writes, on their own budget.
        $owner = $this->storedToken($request, $link) ?? $request->session()->getId();
        $sessionKey = ($polling ? 'try-poll:' : 'try:').$link->id.':'.$owner;
        $ipKey = ($polling ? 'try-poll-ip:' : 'try-ip:').TestLinkSessions::ipHash($request->ip());
        $perSession = $polling ? self::POLLS_PER_MINUTE : self::PER_MINUTE;
        $perIp = $polling ? self::POLLS_PER_IP_PER_MINUTE : self::PER_IP_PER_MINUTE;

        if (RateLimiter::tooManyAttempts($sessionKey, $perSession)
            || RateLimiter::tooManyAttempts($ipKey, $perIp)) {
            abort(Response::HTTP_TOO_MANY_REQUESTS, 'Slow down a little 🌸');
        }

        RateLimiter::hit($sessionKey, 60);
        RateLimiter::hit($ipKey, 60);
    }

    /** A refresh loop must not inflate «اتفتح 24 مرة». */
    private function countView(Request $request, BotTestLink $link): void
    {
        $key = 'try-view.'.$link->id;
        $last = $request->session()->get($key);

        if (is_int($last) && $last > now()->getTimestamp() - self::VIEW_COOLDOWN_SECONDS) {
            return;
        }

        $request->session()->put($key, now()->getTimestamp());
        $this->sessions->recordView($link);
    }
}
