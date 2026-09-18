<?php

namespace App\Bot\Knowledge;

use App\Models\BotKnowledgeEntry;
use Illuminate\Support\Collection;

/**
 * Read access to the bot's knowledge entries (spec §4.2) — only active
 * entries are ever visible to the bot, regardless of who last edited them.
 */
final class KnowledgeBase
{
    public function get(string $key): ?BotKnowledgeEntry
    {
        return BotKnowledgeEntry::query()->where('key', $key)->where('is_active', true)->first();
    }

    /**
     * @param  list<string>  $keys
     * @return Collection<int, BotKnowledgeEntry>
     */
    public function many(array $keys): Collection
    {
        return $keys === [] ? collect() : BotKnowledgeEntry::query()->whereIn('key', $keys)->where('is_active', true)->orderBy('sort')->get();
    }

    public function line(BotKnowledgeEntry $e): string
    {
        return "[{$e->title}] {$e->body}";
    }
}
