<?php

namespace App\Http\Controllers\Web\Bot;

use App\Bot\Brain\BrainSnapshot;
use App\Bot\Brain\Console\BotConsole;
use App\Bot\Brain\Costs\BotCostReport;
use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use App\Models\BotSetting;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * «عقل البوت», «التكلفة» and «إزاي نطوّر البوت» (b2, 2026-10-19), plus the test console and the
 * owner's Anthropic balance.
 */
class BotBrainController extends Controller
{
    use RespondsWithData;

    /** The guide's single source, also copied to docs/round-2026-10-17/BOT-GUIDE.md. */
    public const GUIDE_PATH = 'resources/docs/bot-guide.md';

    public function brain(Request $request, BrainSnapshot $snapshot): Response
    {
        $user = $request->user();
        abort_unless($user->isInboxStaff(), 403);
        $full = $user->isSupervisorOrAbove();

        return Inertia::render('Bot/Brain', [
            'full' => $full,
            'brain' => $full ? $snapshot->build() : $snapshot->summary(),
        ]);
    }

    public function costs(BotCostReport $report): Response
    {
        return Inertia::render('Bot/Costs', [
            'report' => $report->build(),
            'canEditBalance' => request()->user()->isAdmin(),
            'nowLocal' => now(BotCostReport::TZ)->format('Y-m-d\\TH:i'),
        ]);
    }

    public function guide(): Response
    {
        $markdown = is_file(base_path(self::GUIDE_PATH)) ? (string) file_get_contents(base_path(self::GUIDE_PATH)) : '';

        return Inertia::render('Bot/Guide', [
            'html' => Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
        ]);
    }

    public function try(Request $request, BotConsole $console): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:1000'],
            'history' => ['nullable', 'array', 'max:40'],
            'history.*.role' => ['required', 'in:customer,bot'],
            'history.*.text' => ['required', 'string', 'max:2000'],
            'state' => ['nullable', 'array'],
        ]);

        return response()->json(['data' => $console->run($data['text'], $data['history'] ?? [], $data['state'] ?? null)]);
    }

    public function balance(Request $request): HttpResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'balance_usd' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'balance_at' => ['nullable', 'date'],
        ]);

        // The form sends Cairo wall-clock time; never later than now (a projection from the future is meaningless).
        $at = isset($data['balance_at']) ? CarbonImmutable::parse($data['balance_at'], BotCostReport::TZ)->utc() : CarbonImmutable::now();
        $clear = ($data['balance_usd'] ?? null) === null;
        BotSetting::current()->update([
            'anthropic_balance_usd' => $clear ? null : round((float) $data['balance_usd'], 2),
            'anthropic_balance_at' => $clear ? null : ($at->gt(now()) ? now() : $at),
        ]);

        return $this->done($request, ['saved' => true]);
    }
}
