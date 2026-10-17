<?php

namespace App\Http\Controllers\Web;

use App\Channels\ChannelRegistry;
use App\Channels\Contracts\UnhidesComments;
use App\Enums\Platform;
use App\Http\Controllers\Concerns\CommentEndpoints;
use App\Http\Controllers\Controller;
use App\Http\Resources\CommentResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommentController extends Controller
{
    use CommentEndpoints;

    public function index(Request $request, ChannelRegistry $registry): Response
    {
        $filters = $this->commentFilters($request);

        $capabilities = [];
        foreach (Platform::cases() as $platform) {
            $adapter = $registry->adapter($platform);
            $caps = $adapter->capabilities();
            $capabilities[$platform->value] = [
                'private_reply' => $caps->privateReply,
                'hide_comment' => $caps->hideComment,
                // Unhide is offered on TikTok ad comments only (E1); Meta keeps hide-only here.
                'unhide_comment' => $adapter instanceof UnhidesComments && $platform === Platform::TikTok,
            ];
        }

        return Inertia::render('Comments/Index', [
            'comments' => CommentResource::collection($this->commentQuery($request)->cursorPaginate(30)->withQueryString()),
            'filters' => array_merge(['status' => null, 'intent' => null, 'platform' => null, 'post_id' => null], $filters),
            'capabilities' => $capabilities,
        ]);
    }
}
