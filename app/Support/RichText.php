<?php

declare(strict_types=1);

namespace App\Support;

use Mews\Purifier\Facades\Purifier;

/**
 * The one path rich text takes into the database.
 *
 * Rich text is rendered with `{!! !!}`, so anything that reaches a
 * post's `body` or a product's `description` must pass through the `content`
 * preset of the HTML purifier: that is the whitelist that decides what the
 * toolbar can offer, what a page can render, and what can never appear in a
 * stored document. There is intentionally no other writer of these columns and
 * no escape hatch around it -- a value that is not visibly HTML on the way in
 * is still cleaned, because "plain text" from a textarea has no reason to be
 * trusted more than anything else that arrived over HTTP.
 */
final class RichText
{
    /**
     * Sanitize an HTML fragment against the project's content preset.
     *
     * Runs even when the input contains no markup, so a plain-text paste is
     * normalized to the same paragraph shape the editor produces.
     */
    public static function clean(string $html): string
    {
        return Purifier::clean($html, 'content');
    }

    /**
     * Turn a famously-escaped legacy plain-text body into paragraphs.
     *
     * Used by the data migration that upgrades pre-editor records. The ESCAPING
     * IS THE SANITIZING DECISION: legacy text is data from a time when the
     * application treated these columns as plain text and escaped them at
     * render time, so the migration must not trust it to already be inert. Each
     * text node is escaped before it is wrapped, and the whole thing is
     * re-cleaned so the resulting document obeys the same whitelist every other
     * stored body does.
     */
    public static function paragraphsFromLegacyText(string $text): string
    {
        $escaped = e($text);
        $paragraphs = preg_split('/\R{2,}/', trim($escaped)) ?: [];

        $html = collect($paragraphs)
            ->map(fn (string $paragraph): string => '<p>'.trim($paragraph).'</p>')
            ->implode("\n");

        return $html === '' ? '' : self::clean($html);
    }
}
