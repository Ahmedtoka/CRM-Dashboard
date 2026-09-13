<?php

namespace App\Comments\Jobs;

use App\Comments\CommentBot;
use App\Models\Comment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunCommentBot implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Never retried: a retry after a partial success (public reply posted, private
     * reply failed) would post a second public reply on the customer's comment.
     */
    public int $tries = 1;

    public function __construct(public int $commentId) {}

    public function handle(CommentBot $bot): void
    {
        $comment = Comment::find($this->commentId);

        if ($comment === null) {
            return;
        }

        $bot->handle($comment);
    }
}
