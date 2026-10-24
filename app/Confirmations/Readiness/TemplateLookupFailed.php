<?php

namespace App\Confirmations\Readiness;

use RuntimeException;

/** Meta could not be asked about the templates: `code` is a short reason (graph_unreachable, token_refused, no_waba). */
final class TemplateLookupFailed extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
