<?php

declare(strict_types=1);

namespace App\Support\Legal;

use Throwable;

/**
 * Who the operator is, as published on the legal pages.
 *
 * This exists because the registered legal entity used to be a placeholder
 * typed into two Blade templates, which had three consequences that all cost
 * the same thing: publishing the legal pages needed a code change, a deploy
 * and a push; the privacy notice and the terms could disagree about who the
 * operator was; and nothing in the system could tell anyone they had not been
 * filled in.
 *
 * The value is read from settings, so filling it in is an administrative act.
 * The policy and the terms read this one class, so they cannot drift apart.
 *
 * The naming is deliberate. A trading name is not a legal person. Under the
 * Data Protection Act, 2012 (Act 843) the controller has to be identifiable,
 * and the only string that does that is the registered name, so `name()` is
 * blank or it is the registered name -- never a fallback to the site name,
 * because "As-Is-Commerce" is a trading name and printing it where a
 * controller is required would make the page confidently wrong.
 */
final class OperatorIdentity
{
    public function __construct(
        private readonly ?string $name = null,
        private readonly ?string $address = null,
        private readonly ?string $dpcRegistration = null,
    ) {}

    public static function fromSettings(): self
    {
        try {
            return new self(
                self::read('legal_entity_name'),
                self::read('legal_entity_address'),
                self::read('dpc_registration'),
            );
        } catch (Throwable) {
            // No settings table yet, i.e. an install that has not been seeded.
            // An unknown identity is reported as an incomplete one rather than
            // as an error, because "we do not know" and "we know it is blank"
            // must not be allowed to render as "we know and it is fine".
            return new self;
        }
    }

    private static function read(string $key): ?string
    {
        $value = trim((string) settings()->get($key, ''));

        return $value === '' ? null : $value;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function address(): ?string
    {
        return $this->address;
    }

    public function dpcRegistration(): ?string
    {
        return $this->dpcRegistration;
    }

    /**
     * Whether the operator can be named on a public page.
     *
     * Name and address are both required, because a controller that cannot be
     * served with a complaint is not identifiable in any sense that helps
     * anybody. The Commission registration is deliberately *not* required:
     * not having one is a compliance gap, not an identity gap, and failing a
     * deploy over it would be the command insisting on something it has no
     * standing to judge.
     */
    public function isComplete(): bool
    {
        return $this->name !== null && $this->address !== null;
    }

    /**
     * @return list<string>
     */
    public function missing(): array
    {
        $missing = [];

        if ($this->name === null) {
            $missing[] = 'legal_entity_name';
        }

        if ($this->address === null) {
            $missing[] = 'legal_entity_address';
        }

        return $missing;
    }
}
