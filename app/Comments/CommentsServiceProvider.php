<?php

namespace App\Comments;

use Illuminate\Support\ServiceProvider;

class CommentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CommentIngestor::class);
        $this->app->singleton(CommentActions::class);
        $this->app->singleton(CommentBot::class);
    }

    public function boot(): void
    {
        //
    }
}
