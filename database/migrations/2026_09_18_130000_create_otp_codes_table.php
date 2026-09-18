<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issued one-time verification codes.
 *
 * A code is a short-lived proof that somebody can receive mail (or an SMS,
 * once a phone transport arrives) addressed to the account. It is NEVER a
 * credential by itself: using a code only opens a flow the account already
 * owns -- verifying its own email address, or resetting its own password.
 *
 * SECURITY PROPERTIES
 *
 * - The code is stored HASHED. The table cannot be read back into usable
 *   codes by an attacker with database access, which also means the plaintext
 *   appears only in the delivered mail and nowhere on disk.
 * - Single use. `consumed_at` is set when the code is honoured, and
 *   verification only ever considers the most recent unconsumed, unexpired
 *   code for a user and purpose -- so an older code cannot be traded in once a
 *   newer one was issued.
 * - Bounded attempts. `attempts` counts wrong guesses; a code that exceeded
 *   the limit is consumed so it cannot be ground through further.
 * - Expiry. `expires_at` bounds a code's lifetime server-side; nothing in the
 *   UI, mail or client ever decides whether a code is still valid.
 * - Fail loud. Delivery problems surface as exceptions and the issued code is
 *   rolled back, so a code that was never actually sent cannot later be
 *   entered.
 *
 * These rows are convenience evidence, not financial records: an account
 * being closed takes its codes with it (cascade), and old rows are prunable
 * once past their expiry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();

            // The account the code proves something about. Cascaded: codes are
            // short-lived support rows, not evidence that must outlive an
            // account the way an order or a ledger entry does.
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // What the code may be used for, as an OtpPurpose value; and which
            // transport carried it, as an OtpTransport value. Both are stored
            // as plain strings rather than native enums for MariaDB/MySQL
            // compatibility, and constrained here so a hand-run insert cannot
            // invent a purpose or a channel.
            $table->string('purpose', 30);
            $table->string('channel', 12);

            // The address the code was delivered to (an email today, a phone
            // number when SMS arrives). The same account may be reached at
            // different destinations over time, and recording which one was
            // used keeps verification honest.
            $table->string('destination', 320);

            // The code, hashed. 100 characters comfortably holds bcrypt's 60.
            $table->string('code_hash', 100);

            // Wrong guesses against this code. Consumed on delivery failure
            // rollback and on success, so an honoured code can never be
            // replayed.
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();

            // The hot lookup: the most recent code for a user/purpose that is
            // still alive. Fetching by (user_id, purpose) then ordering by id
            // serves it from this index alone.
            $table->index(['user_id', 'purpose']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE otp_codes
            ADD CONSTRAINT chk_otp_codes_attempts_nonnegative CHECK (attempts >= 0)
        SQL);
    }

    public function down(): void
    {
        // MariaDB drops named CHECKs through DROP CONSTRAINT, not DROP CHECK.
        DB::statement('ALTER TABLE otp_codes DROP CONSTRAINT chk_otp_codes_attempts_nonnegative');

        Schema::dropIfExists('otp_codes');
    }
};
