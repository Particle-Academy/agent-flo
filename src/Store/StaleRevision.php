<?php

declare(strict_types=1);

namespace AgentFlo\Store;

use RuntimeException;

/** The write named a revision that is not the current one. Nothing was written. */
final class StaleRevision extends RuntimeException {}
