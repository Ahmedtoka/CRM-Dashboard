<?php

namespace App\Bot\Flows;

/**
 * Where FlowEngine reads a flow's definition from (flow designer §3). Live:
 * the active published row (PublishedFlowDefinitions). The sandbox swaps in
 * one that serves a draft for the flow under test.
 */
interface FlowDefinitionSource
{
    /** The raw definition array, or null when the flow is missing/inactive. */
    public function definition(string $flowKey): ?array;
}
