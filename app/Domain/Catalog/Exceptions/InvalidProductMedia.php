<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use DomainException;

/**
 * A product image operation that is not permitted.
 *
 * Covers the failures that matter operationally: uploading past the gallery
 * limit, an upload that is not an accepted image, storage failing, and a
 * reorder that does not describe the gallery. Each carries the domain's own
 * wording so the admin screen can say exactly why it refused.
 */
final class InvalidProductMedia extends DomainException
{
    public static function limitReached(int $limit): self
    {
        return new self("A product can have at most {$limit} images. Delete one to add another.");
    }

    public static function unsupportedType(): self
    {
        return new self('Only JPEG, PNG, WebP and GIF images are accepted.');
    }

    public static function storeFailed(): self
    {
        return new self('The image could not be stored.');
    }

    public static function invalidOrder(): self
    {
        return new self('The image order does not match this product gallery.');
    }
}
