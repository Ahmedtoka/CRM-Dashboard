<?php

namespace App\Http\Controllers\Concerns;

use App\Comments\CommentActions;
use App\Enums\CommentIntent;
use App\Enums\CommentStatus;
use App\Enums\Platform;
use App\Http\Resources\CommentResource;
use App\Http\Resources\ConversationResource;
use App\Models\Comment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Comment feed and actions shared by the web Comments screen and API v1.
 */
trait CommentEndpoints
{
    public function feed(Request $request): AnonymousResourceCollection
    {
        return CommentResource::collection($this->commentQuery($request)->cursorPaginate(30)->withQueryString());
    }

    public function reply(Request $request, Comment $comment, CommentActions $actions): CommentResource
    {
        Gate::authorize('reply', $comment);

        $data = $request->validate(['text' => ['required', 'string', 'max:2000']]);

        return new CommentResource($actions->reply($comment, $data['text'], $request->user())->load('repliedBy'));
    }

    public function hide(Request $request, Comment $comment, CommentActions $actions): CommentResource
    {
        Gate::authorize('reply', $comment);

        return new CommentResource($actions->hide($comment, $request->user())->load('repliedBy'));
    }

    public function privateReply(Request $request, Comment $comment, CommentActions $actions): JsonResponse
    {
        Gate::authorize('reply', $comment);

        $data = $request->validate(['text' => ['required', 'string', 'max:2000']]);

        $conversation = $actions->privateReply($comment, $data['text'], $request->user());

        return response()->json(['data' => [
            'comment' => (new CommentResource($comment->refresh()))->resolve($request),
            'conversation' => (new ConversationResource($conversation->load(['customer', 'tags'])))->resolve($request),
        ]]);
    }

    /**
     * @return array{status?: ?string, intent?: ?string, platform?: ?string, post_id?: ?int}
     */
    protected function commentFilters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', Rule::enum(CommentStatus::class)],
            'intent' => ['nullable', Rule::enum(CommentIntent::class)],
            'platform' => ['nullable', Rule::enum(Platform::class)],
            'post_id' => ['nullable', 'integer'],
        ]);
    }

    /**
     * @return Builder<Comment>
     */
    protected function commentQuery(Request $request): Builder
    {
        $f = $this->commentFilters($request);
        $user = $request->user();

        return Comment::query()
            ->with(['post', 'customer', 'repliedBy:id,name'])
            ->whereHas('post', function (Builder $q) use ($user, $f) {
                if (! $user->isSupervisorOrAbove()) {
                    $q->whereIn('platform', array_map(fn (Platform $p) => $p->value, $user->platforms()));
                }

                if (! empty($f['platform'])) {
                    $q->where('platform', $f['platform']);
                }
            })
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['intent'] ?? null, fn ($q, $v) => $q->where('intent', $v))
            ->when($f['post_id'] ?? null, fn ($q, $v) => $q->where('post_id', $v))
            ->orderByDesc('id');
    }
}
