<?php

namespace App\Inbox\SavedReplies;

/**
 * A saved reply's body after variable substitution (spec §2.2): nothing is
 * sent automatically — the caller decides what to do with `body`/`missing`.
 */
final readonly class RenderedReply
{
    /**
     * @param  list<string>  $missing  canonical variable keys that resolved empty
     */
    public function __construct(
        public string $body,
        public array $missing,
    ) {}
}
