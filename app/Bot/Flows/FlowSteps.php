<?php

namespace App\Bot\Flows;

use App\Bot\Flows\Steps\BranchesListStep;
use App\Bot\Flows\Steps\BranchStep;
use App\Bot\Flows\Steps\ContactStep;
use App\Bot\Flows\Steps\FlowStep;
use App\Bot\Flows\Steps\ItemChangesStep;
use App\Bot\Flows\Steps\OrderItemsStep;
use App\Bot\Flows\Steps\OrderStep;
use App\Bot\Flows\Steps\PhotoStep;
use App\Bot\Flows\Steps\ProductLinkStep;
use App\Bot\Flows\Steps\RecordCaseStep;
use App\Bot\Flows\Steps\StatusStep;
use Illuminate\Contracts\Container\Container;

/** Step types handled by their own classes (menu/choice/text/name/phone/summary/script/handover/end stay in FlowEngine). */
final class FlowSteps
{
    /** @var array<string, class-string<FlowStep>> */
    private const HANDLERS = [
        'order' => OrderStep::class,
        'order_items' => OrderItemsStep::class,
        'photo' => PhotoStep::class,
        'product_link' => ProductLinkStep::class,
        'branch' => BranchStep::class,
        'branches_list' => BranchesListStep::class,
        'status' => StatusStep::class,
        'record_case' => RecordCaseStep::class,
        // The owner's flows of 2026-09-19.
        'contact' => ContactStep::class,
        'item_changes' => ItemChangesStep::class,
    ];

    public function __construct(private readonly Container $app) {}

    public function for(?string $type): ?FlowStep
    {
        $class = self::HANDLERS[$type ?? ''] ?? null;

        return $class !== null ? $this->app->make($class) : null;
    }
}
