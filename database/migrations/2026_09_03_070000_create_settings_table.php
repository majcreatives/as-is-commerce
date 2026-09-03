<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            $table->string('key', 100)->unique();

            // Stored as text and cast on read according to `type`. Nullable so
            // "configured but deliberately empty" stays distinguishable from
            // "never configured".
            $table->text('value')->nullable();

            // Drives the typed accessors so callers never hand-roll casting.
            $table->string('type', 20)->default('string');

            // Groups the admin screen into sections.
            $table->string('group', 50)->default('general')->index();

            $table->string('label', 150);
            $table->string('description', 500)->nullable();

            // Marks values safe to render publicly (site name, currency
            // symbol). Support contacts and anything operational stay false by
            // default so nothing leaks simply by being added to this table.
            $table->boolean('is_public')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
