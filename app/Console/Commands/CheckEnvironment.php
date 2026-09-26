<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Environment\EnvironmentFinding;
use App\Support\Environment\EnvironmentInspector;
use Illuminate\Console\Command;

/**
 * Says whether this installation is fit to be public, and refuses to lie about it.
 *
 * READ-ONLY AND SAFE TO RUN ANYWHERE, INCLUDING A FRESH INSTALL. It reads
 * configuration, reads one count, and changes nothing: no migration, no write,
 * no repair. A readiness check that fixes what it finds is not a check, it is an
 * unlogged decision.
 *
 * THE EXIT CODE IS THE POINT. `--strict` makes any blocker return a non-zero
 * status, which is what turns this from a thing a human reads into a thing a
 * deploy script cannot get past. The conditions it enforces are the ones an
 * installation gets wrong by copying `.env.example`, which ships
 * `APP_DEBUG=true`, `MAIL_MAILER=log` and `SESSION_SECURE_COOKIE=false` because
 * that file is written for a developer.
 *
 * NEVER PRINTS A SECRET. Its output is meant to be pasted into a ticket, and a
 * command that helps a human verify a production environment must not become
 * the reason a credential ends up in one. Paystack and mail credentials are
 * reported as present or absent and never as values.
 */
class CheckEnvironment extends Command
{
    protected $signature = 'app:check-environment
                            {--strict : Exit non-zero when anything is a blocker}';

    protected $description = 'Report whether the configured environment is safe to expose to the public';

    public function handle(EnvironmentInspector $inspector): int
    {
        $findings = $inspector->inspect();

        $blockers = array_filter($findings, fn (EnvironmentFinding $f): bool => $f->isBlocker());
        $warnings = array_filter($findings, fn (EnvironmentFinding $f): bool => $f->isWarning());

        $this->newLine();
        $this->line('  '.(app()->environment() === 'local' || app()->environment() === 'testing'
            ? 'Environment: local or testing -- developer defaults are allowed here.'
            : 'Environment: '.$this->environmentName().' -- reachable from the internet, so it is held to the strict reading.'));
        $this->newLine();

        foreach ($findings as $finding) {
            $this->line('  '.$finding->line());
        }

        $this->newLine();

        if (! $inspector->databaseReachable()) {
            $this->error('  The database could not be reached, so the checks that read it were skipped.');
            $this->newLine();

            return self::FAILURE;
        }

        if ($blockers === []) {
            $this->info(sprintf(
                '  Nothing blocking. %d warning(s), each one a decision for a person rather than a fault.',
                count($warnings),
            ));
        } else {
            $this->error(sprintf(
                '  %d blocker(s). This installation is not safe to be public, and the reasons are above.',
                count($blockers),
            ));
        }

        $this->newLine();

        // `--strict` is the gate. Without it the command is a report, so a
        // developer on a laptop is not made to fail a build over a log mailer.
        if ($this->option('strict') && $blockers !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function environmentName(): string
    {
        return (string) config('app.env');
    }
}
