<?php

declare(strict_types=1);

namespace AgentFlo\Vocabulary;

use InvalidArgumentException;

/**
 * A configured word or tool name was refused. Thrown at boot and never cleaned
 * up: silent sanitising would hide the mistake.
 */
final class InvalidVocabulary extends InvalidArgumentException {}
