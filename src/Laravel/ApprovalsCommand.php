<?php

declare(strict_types=1);

namespace AgentFlo\Laravel;

use AgentFlo\Approvals\Approvals;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\NullOutput;

/**
 * `php artisan agent-flo:approvals` prints which approvals are on. It runs during
 * `php artisan optimize`, so a deploy log carries it. With `--ci` it exits 1 while
 * any approval is off, for a host that wants its build to fail on that.
 */
final class ApprovalsCommand extends Command
{
    protected $signature = 'agent-flo:approvals {--ci : Exit non-zero when any approval is off}';

    protected $description = 'Show which agent-flo approvals are on, and warn about any that are off.';

    /** @var resource|null where warnings go when this command's output is discarded; tests replace it */
    public static $stderr = null;

    public function handle(Approvals $approvals): int
    {
        foreach (array_keys(Approvals::DECLARED) as $name) {
            $this->line(sprintf('%s: %s', $name, $approvals->required($name) ? 'on' : 'OFF'));
        }
        // `php artisan optimize` runs this with its output discarded, so a warning
        // written only to that output never reaches the deploy log. Measured on a
        // real Laravel 13 app before this branch existed.
        $silenced = $this->output->getOutput() instanceof NullOutput;
        foreach ($approvals->warnings() as $warning) {
            $silenced ? fwrite(self::$stderr ?? STDERR, $warning.PHP_EOL) : $this->warn($warning);
        }

        return $this->option('ci') && $approvals->off() !== [] ? self::FAILURE : self::SUCCESS;
    }
}
