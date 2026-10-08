<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\Product;

/*
 * The data migration that upgrades pre-editor plain-text content.
 *
 * Before the rich-text editor, these columns held plain text that the views
 * escaped. Now they hold whitelisted HTML rendered raw, so a legacy value must
 * be converted to the same paragraph shape the editor produces -- and only
 * ever once. A row that already contains a `<` is left alone, which is what
 * makes the migration idempotent against a partially-applied database.
 */

const LEGACY_CONTENT_MIGRATION = '2026_10_08_120300_upgrade_legacy_plain_text_content.php';

function legacyContentMigration(): object
{
    return require database_path('migrations/'.LEGACY_CONTENT_MIGRATION);
}

beforeEach(function (): void {
    // Deterministic rows, so the expected conversion is written out exactly.
    Post::factory()->published()->create([
        'body' => "Line one.\n\nLine two.",
    ]);

    Product::factory()->create([
        'description' => 'A simple product line.',
    ]);
});

it('paragraphizes legacy plain text into whitelisted html', function (): void {
    legacyContentMigration()->up();

    $postBody = Post::query()->firstOrFail()->fresh()->body;
    $productDescription = Product::query()->firstOrFail()->fresh()->description;

    // The paragraph break survives whatever line ending the cleaner emits --
    // comparing against a local string verbatim would fail on nothing at all.
    $normalised = str_replace("\r\n", "\n", $postBody);

    expect($normalised)->toBe("<p>Line one.</p>\n<p>Line two.</p>")
        ->and($productDescription)->toBe('<p>A simple product line.</p>');
});

it('leaves any value containing a less-than sign untouched', function (): void {
    $body = 'A price below 5 < 10 is a deal.';

    Post::where('body', "Line one.\n\nLine two.")->update(['body' => $body]);

    legacyContentMigration()->up();

    // A `<` could begin markup, so the migration refuses to decide and leaves
    // the value alone rather than guessing at what it was meant to be.
    expect(Post::query()->firstOrFail()->fresh()->body)->toBe($body);
});

it('does not touch a value that already contains markup', function (): void {
    $body = '<h2>Header</h2><p>Some <em>styled</em> text.</p>';

    Post::where('body', "Line one.\n\nLine two.")->update(['body' => $body]);

    legacyContentMigration()->up();

    expect(Post::query()->firstOrFail()->fresh()->body)->toBe($body);
});

it('is idempotent against an already-upgraded database', function (): void {
    legacyContentMigration()->up();

    $postBody = Post::query()->firstOrFail()->fresh()->body;
    $productDescription = Product::query()->firstOrFail()->fresh()->description;

    legacyContentMigration()->up();

    expect(Post::query()->firstOrFail()->fresh()->body)->toBe($postBody)
        ->and(Product::query()->firstOrFail()->fresh()->description)->toBe($productDescription);
});
