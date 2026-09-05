<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications, and what a customer wants to be told about.
 *
 * LARAVEL'S OWN TABLE, EXTENDED. The `notifications` shape here is the
 * framework's -- uuid primary key, morphed notifiable, JSON payload, nullable
 * `read_at` -- because `User` already carries `Notifiable` and building a
 * second store beside it would mean two ways to record the same thing. Three
 * columns are added for what this platform needs and the framework does not
 * provide.
 *
 * `event_key` IS THE IDEMPOTENCY DEFENCE. It is a deterministic string derived
 * from the business event and its recipient -- not from the message text,
 * which may be reworded. A unique index makes a repeat delivery a database
 * refusal rather than an application check that would race two simultaneous
 * webhook retries. The same defence, and the same reasoning, as
 * `payment_webhook_events`.
 *
 * The mail columns record what happened to the supplementary channel. Mail is
 * never allowed to fail a business transaction, so when it fails the failure
 * has to be written down somewhere an administrator can find it.
 *
 * ONE CHANNEL, SO NO CHANNEL TABLE. In-app is the notification itself and mail
 * is the only other channel, so its outcome lives on the row. A second
 * delivery channel would justify a `notification_deliveries` table; one does
 * not.
 *
 * NOTHING SENSITIVE IS STORED. No payment credentials, no card details, no
 * tokens, no OTP secrets. The payload carries a title, a message, a link and
 * identifiers -- the same things the customer can already see on their own
 * pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            // Laravel's own shape, so the framework's Notifiable trait, its
            // DatabaseChannel and its DatabaseNotification model all work
            // unchanged.
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // ---- Added for this platform ---------------------------------

            // What the notification is about, as a stable identifier. Held in
            // its own column rather than only inside the JSON payload so it
            // can be filtered and indexed.
            $table->string('event_type', 60);

            // One business event, one notification per recipient. Derived from
            // the event and the recipient, never from the wording.
            $table->string('event_key', 191)->nullable()->unique();

            // What happened to the email, if one was warranted. Recorded
            // rather than thrown, because a failed email must never fail the
            // transaction that caused it.
            $table->string('mail_status', 20)->nullable();
            $table->string('mail_failure_reason', 500)->nullable();
            $table->timestamp('mail_sent_at')->nullable();

            // The customer's own list: their notifications, newest first.
            $table->index(['notifiable_type', 'notifiable_id', 'created_at']);
            // The unread badge, which runs on every authenticated page.
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            // The administrative view of delivery failures.
            $table->index(['mail_status', 'created_at']);
            $table->index('event_type');
        });

        Schema::table('users', function (Blueprint $table) {
            // Which optional categories this customer wants. Null means every
            // default, so an existing account needs no backfill and a new one
            // needs no row written before it can be notified.
            //
            // A column rather than a table: the categories are a short fixed
            // list, every read wants all of them at once, and a join to fetch
            // two booleans would be more machinery than the problem deserves.
            $table->json('notification_preferences')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });

        Schema::dropIfExists('notifications');
    }
};
