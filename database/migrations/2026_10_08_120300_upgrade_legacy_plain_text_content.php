<?php

declare(strict_types=1);

use App\Support\RichText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Upgrade the blog bodies and product descriptions that were written before
 * the rich-text editor existed.
 *
 * Those columns held plain text and were escaped at render time. Now they hold
 * sanitized HTML and are rendered with `{!! !!}`, so a legacy value must be
 * converted exactly once, into the same whitelisted shape an editor-produced
 * value has, before raw rendering starts trusting it. A row that already
 * contains a `<` is left alone: it is either editor output already or it
 * arrived through a path that must not be disturbed by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->paragraphize('posts', 'body');
        $this->paragraphize('products', 'description');
    }

    public function down(): void
    {
        // A reversible transform does not exist: escaping and paragraph-wrap is
        // not a bijection, and unescaping stored HTML would resurrect markup
        // that the current whitelist has already discarded.
    }

    /**
     * Convert every plain-text row in one column to whitelisted paragraphs.
     *
     * Chunked by id so a large table is not one giant transaction. The value on
     * a row already containing markup is unchanged, which is what makes the
     * migration idempotent against a partially-applied database.
     */
    private function paragraphize(string $table, string $column): void
    {
        DB::table($table)
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->whereRaw("{$column} NOT LIKE ?", ['%<%'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($table, $column): void {
                foreach ($rows as $row) {
                    $converted = RichText::paragraphsFromLegacyText((string) $row->{$column});

                    DB::table($table)
                        ->where('id', $row->id)
                        ->update([$column => $converted]);
                }
            });
    }
};
