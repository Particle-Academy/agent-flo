<?php

declare(strict_types=1);

namespace AgentFlo\Store;

/** An opaque revision: the content and a counter, so an unchanged re-save still moves it. */
final class Revision
{
    /** @param array<string,mixed> $schema */
    public static function of(array $schema, int $version): string
    {
        return substr(hash('sha256', $version.':'.json_encode($schema)), 0, 24);
    }
}
