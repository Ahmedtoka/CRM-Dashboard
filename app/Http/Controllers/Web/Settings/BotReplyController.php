<?php

namespace App\Http\Controllers\Web\Settings;

use App\Bot\Replies\ReplyCatalog;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «كل ردود البوت» (owner, 2026-09-22): every reply the bot can give next to the moment it is
 * given, on one page. Knowledge rows are saved through BotKnowledgeController::updateEntry.
 */
class BotReplyController extends Controller
{
    public function index(ReplyCatalog $catalog): Response
    {
        return Inertia::render('settings/BotReplies', $catalog->build());
    }
}
