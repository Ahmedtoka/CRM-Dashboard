<?php

namespace App\Http\Controllers\Web\Settings;

use App\Bot\Language\ArabicOverrides;
use App\Bot\Replies\ReplyCatalog;
use App\Http\Controllers\Concerns\RespondsWithData;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * «كل ردود البوت» (owner, 2026-09-22): every reply the bot can give next to the moment it is
 * given, on one page. Knowledge rows are saved through BotKnowledgeController::updateEntry.
 */
class BotReplyController extends Controller
{
    use RespondsWithData;

    public function index(ReplyCatalog $catalog): Response
    {
        return Inertia::render('settings/BotReplies', $catalog->build());
    }

    /** The owner's wording for a sentence written in code (ArabicOverrides): `source` is the masked original. */
    public function updateText(Request $request, ArabicOverrides $overrides): HttpResponse
    {
        $data = $request->validate(['source' => ['required', 'string', 'max:4000'], 'text' => ['required', 'string', 'max:2000']]);

        // Every value the sentence fills in (an order number, a name, a link) must still be in her text.
        abort_if($overrides->save($data['source'], $data['text']) === null, 422, __('errors.bot_replies.values_missing'));

        return $this->done($request, ['ok' => true]);
    }

    /** Back to the original sentence. */
    public function resetText(Request $request, ArabicOverrides $overrides): HttpResponse
    {
        $data = $request->validate(['source' => ['required', 'string', 'max:4000']]);
        $overrides->forget($data['source']);

        return $this->done($request, ['ok' => true]);
    }
}
