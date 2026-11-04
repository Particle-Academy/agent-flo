<?php

declare(strict_types=1);

namespace AgentFlo;

use RuntimeException;

/**
 * A refusal with a stable code. Codes reach logs, tool results and APIs; a
 * message from somewhere else never does.
 */
final class Refused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
