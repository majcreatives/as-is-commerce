<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\Exceptions\InvalidContentMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Storing and removing the one image a partner logo or a success-story photo
 * may carry.
 *
 * ONE IMAGE, NOT A GALLERY. This is not the product gallery: a partner has a
 * logo and a story has a photograph, so there is nothing to order and no limit
 * to enforce. What does need enforcing is what an uploaded file may become.
 *
 * THE WHITELIST IS THE DEFENCE THAT SURVIVES A BYPASSED COMPONENT. The admin
 * form validates the upload, but a crafted request can skip that, so the
 * extension AND the MIME type are re-checked here. Size is re-checked for the
 * same reason. A file is only ever written under partners/, success-stories/
 * or posts/ with a uuid name, one of those extensions, and within the size
 * limit -- which is what stops an uploaded disguise from being served as
 * something executable, and what stops one oversized request from becoming a
 * memory spike on a shared host.
 *
 * DELETES ARE SCOPED. Only a path inside the directory this service writes is
 * ever deleted, so a hand-edited column value cannot turn "remove this logo"
 * into "delete some unrelated public file".
 */
class ContentMediaService
{
    /**
     * The largest image this service will write, in kilobytes.
     *
     * Public and named so the admin form's rule can quote it instead of
     * restating 2048. Two places stating one number is how a limit starts
     * being enforced on the path somebody remembered and nowhere else.
     *
     * A logo and a photograph are both display-sized, and on the shared host a
     * large upload is a memory spike rather than a useful picture. 2 MB is
     * generous for either.
     */
    public const MAX_KILOBYTES = 2048;

    /** The directories this service is allowed to write to and delete from. */
    private const DIRECTORIES = ['partners', 'success-stories', 'posts'];

    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /** @var list<string> */
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Store an upload and return the path to record on the row.
     *
     * @return string The path, relative to the public disk.
     */
    public function store(UploadedFile $file, string $directory): string
    {
        $this->guardDirectory($directory);

        // Checked here, not only in the form. The admin component validates
        // first and gives the uploader a readable message, but a crafted request
        // skips validation entirely -- so the size has to be re-checked at the
        // same boundary as the extension, or it protects one caller.
        if ($file->getSize() > self::MAX_KILOBYTES * 1024) {
            throw InvalidContentMedia::tooLarge();
        }

        $path = $file->storeAs(
            $directory,
            Str::uuid()->toString().'.'.$this->allowedExtension($file),
            'public',
        );

        if ($path === false) {
            throw InvalidContentMedia::storeFailed();
        }

        return $path;
    }

    /**
     * Delete a previously stored file, if it is one of ours.
     *
     * Silently ignoring a path outside the permitted directories is deliberate:
     * removing an image should not be blocked by a column that was edited by
     * hand to point somewhere unexpected, and it certainly should not delete
     * that somewhere.
     */
    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        foreach (self::DIRECTORIES as $directory) {
            if (Str::startsWith($path, "{$directory}/")) {
                Storage::disk('public')->delete($path);

                return;
            }
        }
    }

    /**
     * The extension to store under, verifying both name and content.
     */
    private function allowedExtension(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)
            || ! in_array((string) $file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw InvalidContentMedia::unsupportedType();
        }

        return $extension === 'jpeg' ? 'jpg' : $extension;
    }

    private function guardDirectory(string $directory): void
    {
        if (! in_array($directory, self::DIRECTORIES, true)) {
            throw InvalidContentMedia::unknownDirectory();
        }
    }
}
