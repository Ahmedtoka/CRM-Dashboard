<?php

namespace App\Bot\Flows;

use RuntimeException;

/**
 * Thrown by `FlowDrafts::publish()` / `restore()` when the definition being
 * published fails `FlowDefinition::validate()` or `validateReferences()`.
 * Carries the full error list so callers (the settings controller, Task 2)
 * can return it as a 422 without re-running validation.
 */
final class FlowValidationException extends RuntimeException
{
    /** @param  list<string>  $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Flow definition is invalid: '.implode('; ', $errors));
    }
}
