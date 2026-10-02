<?php

declare(strict_types=1);

namespace App\Domain\Content\Exceptions;

use DomainException;

/**
 * A partner or success-story image operation that is not permitted.
 *
 * Three failures only: the upload is not an accepted image, the file could not
 * be stored, and the caller asked for a directory this service does not own.
 * All carry wording an admin screen can show verbatim.
 */
final class InvalidContentMedia extends DomainException
{
    public static function unsupportedType(): self
    {
        return new self('Only JPEG, PNG, WebP and GIF images are accepted.');
    }

    public static function storeFailed(): self
    {
        return new self('The image could not be stored.');
    }

    public static function unknownDirectory(): self
    {
        return new self('That is not a location this service stores images in.');
    }
}
