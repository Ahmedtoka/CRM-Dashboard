<?php

namespace App\Queue;

use App\Bot\Flows\{FlowPrompter, FlowScripts};
use App\Models\BotKnowledgeEntry;

/**
 * The handover queue's customer-facing texts, editable from Settings → Bot
 * replies like any other script (`bot_knowledge_entries` key `script.queue_*`).
 * Falls back to the FlowScripts default only when the owner has not touched
 * the row yet; once it exists and is turned off, the queue says nothing.
 */
class QueueScripts
{
    public function __construct(private readonly FlowPrompter $prompter) {}

    public function text(string $key, array $vars = []): ?string
    {
        $body = $this->prompter->script($key, $vars);
        if ($body === null) {
            if (BotKnowledgeEntry::query()->where('key', 'script.'.$key)->exists()) {
                return null; // the owner turned it off
            }
            $body = trim((string) (FlowScripts::all()[$key]['body'] ?? ''));
            if ($body === '') {
                return null;
            }
        }
        foreach ($vars as $k => $v) {
            $body = str_replace('{'.$k.'}', (string) $v, $body);
        }

        return $body;
    }
}
