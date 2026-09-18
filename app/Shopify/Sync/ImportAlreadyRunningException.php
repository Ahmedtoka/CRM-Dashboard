<?php

namespace App\Shopify\Sync;

use RuntimeException;

/**
 * Thrown by BulkImporter::start() while an import chain is active (a stage is
 * `running` and was updated within the last 15 minutes).
 */
final class ImportAlreadyRunningException extends RuntimeException
{
    public function __construct(public readonly string $stage)
    {
        parent::__construct("A Shopify import is already running (stage {$stage}).");
    }
}
