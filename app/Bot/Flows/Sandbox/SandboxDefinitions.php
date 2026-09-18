<?php

namespace App\Bot\Flows\Sandbox;

use App\Bot\Flows\FlowDefinitionSource;
use App\Bot\Flows\FlowDrafts;
use App\Bot\Flows\PublishedFlowDefinitions;
use App\Models\BotFlow;

/**
 * Definitions for a sandbox run: the flow under test serves its draft
 * (source "draft", falling back to the published definition when there is no
 * draft) or its published definition — even when the flow is inactive. Every
 * other flow serves its live, active published definition.
 */
final class SandboxDefinitions implements FlowDefinitionSource
{
    public function __construct(
        private readonly BotFlow $flow,
        private readonly string $source,
        private readonly FlowDrafts $drafts,
        private readonly PublishedFlowDefinitions $published,
    ) {}

    public function definition(string $flowKey): ?array
    {
        if ($flowKey !== $this->flow->key) {
            return $this->published->definition($flowKey);
        }

        $def = $this->source === 'draft' ? $this->drafts->draftFor($this->flow) : $this->flow->definition;

        return is_array($def) ? $def : null;
    }
}
