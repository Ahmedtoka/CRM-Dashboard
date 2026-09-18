<?php

namespace App\Bot\Flow;

use App\Models\BotIntent;
use Illuminate\Support\Collection;

/** The active intents (table `bot_intents`, spec §2.3), loaded once per instance. */
class IntentCatalog
{
    /** @var Collection<int, BotIntent>|null */
    private ?Collection $intents = null;

    /** @param  Collection<int, BotIntent>  $intents */
    public static function fromCollection(Collection $intents): self
    {
        $catalog = new self;
        $catalog->intents = $intents->values();

        return $catalog;
    }

    /** @return Collection<int, BotIntent> */
    public function active(): Collection
    {
        return $this->intents ??= BotIntent::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get();
    }

    public function find(string $key): ?BotIntent
    {
        return $this->active()->first(fn (BotIntent $i) => $i->key === $key);
    }

    /** @return list<array{key:string, label:string, hints:list<string>}> */
    public function forPrompt(): array
    {
        return $this->active()
            ->map(fn (BotIntent $i) => [
                'key' => (string) $i->key,
                'label' => (string) ($i->label_ar ?? $i->key),
                'hints' => array_values(array_map('strval', $i->keywords ?? [])),
            ])
            ->values()
            ->all();
    }
}
