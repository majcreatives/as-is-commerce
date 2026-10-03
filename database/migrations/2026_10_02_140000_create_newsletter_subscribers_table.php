<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Newsletter subscribers: addresses somebody asked to hear from us, and the
 * record that they proved it.
 *
 * SELF-HOSTED, AND THAT IS THE WHOLE POINT. There is no mailing provider in
 * this project and this migration does not pretend to be one. What exists is the
 * list, the consent record, and an unsubscribe that works without a login --
 * the parts that have to be ours for the promise to mean anything.
 *
 * THERE IS NO SENDING MACHINERY HERE, deliberately. Nothing in this table can
 * email anybody. That is why the signup copy must not promise a schedule:
 * "weekly drops" is a commitment to a stranger's inbox, and there is currently
 * no code that can keep it. Collecting addresses first and agreeing how they get
 * used before telling anybody they will be written to is the honest order.
 *
 * DOUBLE OPT-IN, so `status` MEANS SOMETHING. A row is created `pending` and
 * only becomes `subscribed` when the owner follows a confirmation link. The
 * alternative -- subscribing the moment the form is submitted -- means the list
 * holds addresses nobody proved they own, and one person can subscribe a
 * stranger. The cost is one extra email, which is the point.
 *
 * TWO TOKENS, BECAUSE THEY HAVE DIFFERENT LIFETIMES.
 *   - confirmation_token is rotated every time a confirmation is sent and
 *     destroyed the moment it is used. If it survived, an old confirmation
 *     email could be replayed to re-subscribe somebody who has since
 *     unsubscribed, which is exactly the thing unsubscribe exists to prevent.
 *   - unsubscribe_token is written once, when the row is created, and is never
 *     rotated afterwards -- not even when somebody re-subscribes. A campaign may
 *     go out years later and carry this link, and a token that changed when the
 *     person re-signed-up would leave an email already sitting in their inbox
 *     with a link that silently fails to unsubscribe them.
 *
 * BOTH ARE RANDOM AND BOTH ARE STORED. A signed URL would have avoided the
 * columns, but a signature is derived from APP_KEY, and rotating it to
 * invalidate old links would silently break unsubscribes people already hold.
 * Revoking a newsletter is not something you do by redeploying.
 *
 * email IS STORED AS ENTERED BUT COMPARED NORMALIZED. The unique index is on
 * the stored value, so writes normalize first (NewsletterSubscriber::normalize)
 * and two spellings of one address cannot both land.
 *
 * NO IP ADDRESS, DELIBERATELY. It would marginally strengthen a consent
 * defence, and it is also personal data that the published privacy notice does
 * not currently mention. Collecting it quietly to improve our own evidence is
 * the wrong trade; the consent timestamp is the record that matters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();

            $table->string('email', 254);

            // pending | subscribed | unsubscribed. A string with a CHECK rather
            // than a database ENUM, so adding a fourth state later is a
            // migration that widens a constraint instead of one that rewrites a
            // column definition on every row.
            $table->string('status', 20)->default('pending');

            // Rotated per confirmation, destroyed on use. Nullable because a
            // confirmed subscriber no longer has one.
            $table->string('confirmation_token', 64)->nullable();

            // Created once, kept forever, so a link in an old campaign email
            // still works. This is the one column that is deliberately never
            // rotated.
            $table->string('unsubscribe_token', 64);

            // When the person proved they own the address -- not when the form
            // was filled in. Null until the confirmation link is followed, which
            // is what makes it evidence of anything.
            $table->timestamp('consented_at')->nullable();

            // When the person asked to stop. Kept separate from `status` so an
            // address that left and came back has both halves of its history.
            $table->timestamp('unsubscribed_at')->nullable();

            // Where on the site the form was used. Not a tracking system; it
            // exists so "which of our signup points is working" has an answer
            // without adding analytics.
            $table->string('source', 40)->nullable();

            $table->timestamps();

            $table->unique('email');

            // Both tokens are looked up by their own value on every
            // confirmation or unsubscribe click.
            $table->unique('confirmation_token', 'newsletter_confirmation_token_unique');
            $table->unique('unsubscribe_token', 'newsletter_unsubscribe_token_unique');

            // The admin list reads subscribed addresses in that order; the
            // "how many are waiting to confirm" count reads pending.
            $table->index(['status', 'created_at'], 'newsletter_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
    }
};
