<?php

declare(strict_types=1);

namespace AgentFlo\Laravel;

use AgentFlo\Approvals\Approvals;
use Illuminate\Console\Command;

/**
 * `php artisan agent-flo:approvals` prints which approvals are on. It runs during
 * `php artisan optimize`, so a deploy log carries it. With `--ci` it exits 1 while
 * any approval is off, for a host that wants its build to fail on that.
 */
final class ApprovalsCommand extends Command
{
    protected $signature = 'agent-flo:approvals {--ci : Exit non-zero when any approval is off}';

    protected $description = 'Show which agent-flo approvals are on, and warn about any that are off.';

    public function handle(Approvals $approvals): int
    {
        foreach (array_keys(Approvals::DECLARED) as $name) {
            $this->line(sprintf('%s: %s', $name, $approvals->required($name) ? 'on' : 'OFF'));
        }
        foreach ($approvals->warnings() as $warning) {
            $this->warn($warning);
        }

        return $this->option('ci') && $approvals->off() !== [] ? self::FAILURE : self::SUCCESS;
    }
}
