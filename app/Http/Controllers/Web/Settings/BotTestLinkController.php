<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Models\BotTestLink;
use App\Models\BotTestSession;
use App\TestLinks\TestLinkSessions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings → «روابط التجربة» (design 2026-09-21 §1): the owner makes a link,
 * copies it into the team chat, watches how many times it was opened and how
 * many runs it produced, and stops it when the testing is done.
 */
class BotTestLinkController extends Controller
{
    use RespondsWithData;

    public function __construct(private readonly TestLinkSessions $sessions) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('settings/BotTestLinks', [
            'links' => $this->payload(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);

        $link = BotTestLink::create($data + [
            'token' => BotTestLink::newToken(),
            'created_by' => $request->user()->id,
        ]);

        // Created up front so the link is ready the moment the first tester opens it.
        $this->sessions->channelAccount($link);

        return $this->done($request, ['link' => $this->row($link->fresh()), 'links' => $this->payload()]);
    }

    public function update(Request $request, BotTestLink $testLink): Response
    {
        $data = $this->validated($request, $testLink);

        $wasOpen = $testLink->isOpen();
        $testLink->fill($data)->save();

        // Stopping a link ends its sessions immediately (design §6).
        if ($wasOpen && ! $testLink->fresh()->isOpen()) {
            $this->sessions->endAllFor($testLink);
        }

        return $this->done($request, ['link' => $this->row($testLink->fresh()), 'links' => $this->payload()]);
    }

    public function destroy(Request $request, BotTestLink $testLink): Response
    {
        $this->sessions->endAllFor($testLink);

        // The conversations and their transcripts stay in the inbox; only the link,
        // its runs and its funnel trail go, so a deleted link cannot be reopened.
        $testLink->delete();

        return $this->done($request, ['links' => $this->payload()]);
    }

    /** The runs of one link, newest first — the «الجلسات» drawer on the page. */
    public function sessions(Request $request, BotTestLink $testLink): Response
    {
        $rows = $testLink->sessions()
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (BotTestSession $s) => [
                'id' => $s->id,
                'label' => $s->label(),
                'name' => $s->tester_name,
                'run_no' => (int) $s->run_no,
                'conversation_id' => $s->conversation_id,
                'device_family' => $s->device_family,
                'messages_count' => (int) $s->messages_count,
                'started_at' => $s->started_at?->toIso8601String(),
                'last_seen_at' => $s->last_seen_at?->toIso8601String(),
                'ended_at' => $s->ended_at?->toIso8601String(),
                'ended_reason' => $s->ended_reason,
                'duration_seconds' => $s->durationSeconds(),
            ])
            ->all();

        return $this->done($request, ['sessions' => $rows]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?BotTestLink $link = null): array
    {
        $required = $link === null ? 'required' : 'sometimes';

        return $request->validate([
            'label' => [$required, 'string', 'min:2', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'max_sessions' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'max_messages_per_session' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:500'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function payload(): array
    {
        // Aliased away from `sessions_count`: that is a real column on the table
        // (the running total the tester page increments), not a relation count.
        return BotTestLink::query()
            ->withCount([
                'sessions as runs_count',
                'sessions as live_sessions_count' => fn ($q) => $q->whereNull('ended_at'),
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn (BotTestLink $link) => $this->row($link))
            ->all();
    }

    /** @return array<string, mixed> */
    private function row(BotTestLink $link): array
    {
        return [
            'id' => $link->id,
            'label' => $link->label,
            'token' => $link->token,
            'url' => $link->url(),
            'is_active' => (bool) $link->is_active,
            'is_open' => $link->isOpen(),
            'expires_at' => $link->expires_at?->toIso8601String(),
            'max_sessions' => $link->max_sessions,
            'max_messages_per_session' => $link->messageCap(),
            'views_count' => (int) $link->views_count,
            'sessions_count' => (int) $link->sessions_count,
            'runs_count' => (int) ($link->getAttribute('runs_count') ?? $link->sessions()->count()),
            'live_sessions' => (int) ($link->getAttribute('live_sessions_count') ?? 0),
            'last_opened_at' => $link->last_opened_at?->toIso8601String(),
            'created_at' => $link->created_at?->toIso8601String(),
        ];
    }
}
