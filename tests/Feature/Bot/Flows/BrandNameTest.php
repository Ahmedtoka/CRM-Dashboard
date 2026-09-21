<?php

use App\Models\BotKnowledgeEntry;

it('writes the brand as «Le Voile» in every seeded text, never in Arabic letters', function () {
    // Left in Arabic, the English side re-transliterated it on every reply.
    expect(BotKnowledgeEntry::where('body', 'like', '%لوفوال%')->orWhere('title', 'like', '%لوفوال%')->count())->toBe(0)
        ->and(BotKnowledgeEntry::where('key', 'script.greeting')->value('body'))->toContain('Le Voile');
});

it('rewrites an old Arabic spelling left in the database, once', function () {
    BotKnowledgeEntry::where('key', 'script.greeting')->update(['body' => 'مع حضرتك ميار من لوفوال']);

    // Run the one migration again: the suite has already migrated, so nothing is pending.
    (require database_path('migrations/2026_09_21_700010_write_brand_name_in_latin.php'))->up();

    expect(BotKnowledgeEntry::where('key', 'script.greeting')->value('body'))->toBe('مع حضرتك ميار من Le Voile');
});
