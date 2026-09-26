<?php

declare(strict_types=1);

namespace App\Support\Environment;

/**
 * One environment fact, and how bad it is.
 *
 * The severity is the whole point. A command that lists every difference from
 * a wishlist trains its reader to skim, and the one line that matters gets
 * skipped along with the rest. So there are exactly three answers:
 *
 *   ok        Right. Said, so its absence is meaningful.
 *   warning   Real, survivable, and somebody should decide about it.
 *   blocker   The site is not safe to be public. A deploy must not pass.
 *
 * `ok` exists to be printed. A checklist that only speaks up when it is upset
 * cannot be distinguished from a checklist that is broken.
 *
 * The value carries the *consequence* rather than the condition, because a
 * condition ("MAIL_MAILER=log") means nothing to whoever has to act on it and
 * the consequence ("every receipt and password reset is recorded as sent and
 * never delivered") is the thing that makes somebody move.
 */
final class EnvironmentFinding
{
    private function __construct(
        public readonly string $key,
        public readonly string $severity,
        public readonly string $finding,
        public readonly ?string $consequence = null,
    ) {}

    public static function ok(string $key, string $finding): self
    {
        return new self($key, 'ok', $finding);
    }

    public static function warning(string $key, string $finding, string $consequence): self
    {
        return new self($key, 'warning', $finding, $consequence);
    }

    public static function blocker(string $key, string $finding, string $consequence): self
    {
        return new self($key, 'blocker', $finding, $consequence);
    }

    public function isBlocker(): bool
    {
        return $this->severity === 'blocker';
    }

    public function isWarning(): bool
    {
        return $this->severity === 'warning';
    }

    /**
     * What is printed.
     *
     * Never the value of anything secret. This output is meant to be pasted
     * into a ticket, and a command that helps a human verify a production
     * environment must not become the reason a credential ends up in one.
     */
    public function line(): string
    {
        $line = match ($this->severity) {
            'blocker' => 'BLOCKER  '.$this->key.': '.$this->finding,
            'warning' => 'WARNING  '.$this->key.': '.$this->finding,
            default => 'ok       '.$this->key.': '.$this->finding,
        };

        if ($this->consequence !== null) {
            $line .= "\n            -> ".$this->consequence;
        }

        return $line;
    }
}
