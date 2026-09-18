<?php

namespace App\Bot\Flows;

use App\Models\BotFlow;

/** The live definitions: the published `bot_flows.definition` of an active flow. */
final class PublishedFlowDefinitions implements FlowDefinitionSource
{
    public function definition(string $flowKey): ?array
    {
        $def = BotFlow::active($flowKey)?->definition;

        return is_array($def) ? $def : null;
    }
}
