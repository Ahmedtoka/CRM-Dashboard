<?php

namespace App\Http\Controllers\Web;

use App\Channels\ChannelRegistry;
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
            $caps = $registry->adapter($platform)->capabilities();
            $capabilities[$platform->value] = ['private_reply' => $caps->privateReply, 'hide_comment' => $caps->hideComment];
        }

        return Inertia::render('Comments/Index', [
            'comments' => CommentResource::collection($this->commentQuery($request)->cursorPaginate(30)->withQueryString()),
            'filters' => array_merge(['status' => null, 'intent' => null, 'platform' => null, 'post_id' => null], $filters),
            'capabilities' => $capabilities,
        ]);
    }
}
