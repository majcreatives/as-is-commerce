<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a customer's things go, and how they get there.
 *
 * THREE TABLES, AND THE DIVISION IS THE POINT:
 *
 *   addresses             A customer's own address book. Editable, deletable,
 *                         theirs. Changing one changes nothing that has
 *                         already shipped.
 *
 *   deliveries            One physical package for one order, carrying its own
 *                         frozen copy of the address it is going to.
 *
 *   delivery_transitions  Every move, append-only, with who and when.
 *
 * THE SNAPSHOT IS THE WHOLE REASON FOR THE FIRST TWO BEING SEPARATE. A
 * customer who moves house in March must not silently redirect a package that
 * went out in February, and an order from last year must still say where it
 * actually went. So a delivery copies the address rather than pointing at it,
 * and a trigger refuses to let that copy change once anybody has started
 * working on the package.
 *
 * ONE DELIVERY PER ORDER, enforced by a unique index rather than by an
 * application check that two concurrent fulfilments could both pass. Two
 * delivery rows for one order would mean two packages, or two people packing
 * the same one.
 *
 * NO MONEY LIVES HERE. Delivery is not a pricing system: what the customer
 * paid for delivery was decided at checkout, is frozen on the order, and is
 * none of this domain's business. There is deliberately no amount column, no
 * rate, no zone and no weight -- a shipping pricing engine is a different
 * stage, and an unused column is an invitation to build one by accident.
 *
 * NO CREDITS EITHER. Auction bid credits are consumed permanently, and nothing
 * about a package moving returns any of them. No column here could express
 * one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();

            // Cascade: an address book is personal data with no financial
            // weight of its own. What was actually delivered to lives on the
            // delivery, which survives independently.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // What the customer calls it. "Home", "The shop", "Mum's place".
            $table->string('label', 60)->nullable();

            // Who receives it, which is not always the account holder --
            // somebody buying a present sends it to somebody else.
            $table->string('recipient_name', 120);
            // E.164, normalized before storage like every other number on this
            // platform, so a rider dials one canonical form.
            $table->string('recipient_phone', 20);

            // ---- The address itself ---------------------------------------
            //
            // Ghana-shaped, and forgiving. Many Ghanaian addresses are a
            // description and a landmark rather than a street number, so only
            // the parts that are always meaningful are required.
            $table->string('address_line', 200);
            $table->string('area', 120)->nullable();
            $table->string('city', 120);
            $table->string('region', 120)->nullable();

            // GhanaPostGPS, when the customer has one. Never required: most
            // people do not know theirs, and demanding it would block the
            // checkout of everybody who does not.
            $table->string('digital_address', 20)->nullable();

            $table->string('landmark', 200)->nullable();
            $table->string('instructions', 500)->nullable();

            // At most one per customer, enforced in the service rather than by
            // a partial index MySQL does not support.
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();

            // Unique: one order, one package. Restricted, because a delivery
            // is the record of a physical thing changing hands and deleting
            // the order would destroy what it was for.
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            // Ours, not a courier's. Readable and quotable, and it makes no
            // claim to be verifiable anywhere outside this platform.
            $table->string('reference', 32)->unique();

            $table->string('status', 30)->default('pending');

            // ---- The frozen address ---------------------------------------
            //
            // A copy, not a reference. Nullable as a set, because an auction
            // winner's order is created by the closing sweep, when nobody is
            // there to choose an address -- they supply one afterwards, and
            // the delivery cannot leave `pending` until they have.
            $table->string('recipient_name', 120)->nullable();
            $table->string('recipient_phone', 20)->nullable();
            $table->string('address_line', 200)->nullable();
            $table->string('area', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('digital_address', 20)->nullable();
            $table->string('landmark', 200)->nullable();
            $table->string('instructions', 500)->nullable();

            // Which book entry it was copied from, for support to trace. Null
            // on delete: the copy is what matters, and the customer is free to
            // tidy their address book afterwards.
            $table->foreignId('source_address_id')->nullable()
                ->constrained('addresses')->nullOnDelete();

            // ---- Manual handling -------------------------------------------
            //
            // Free text on purpose. There is no courier integration and no
            // provider list to constrain this to; pretending otherwise would
            // imply a tracking number somebody could look up.
            $table->string('carrier', 120)->nullable();
            $table->string('tracking_reference', 100)->nullable();

            // Internal. Never rendered to a customer.
            $table->string('staff_notes', 1000)->nullable();

            // ---- Proof of delivery, as far as a manual process goes --------
            $table->string('received_by', 120)->nullable();
            $table->string('delivery_note', 500)->nullable();

            // ---- Failure ---------------------------------------------------
            $table->string('failure_reason', 40)->nullable();
            $table->string('failure_note', 500)->nullable();
            // How many attempts have been made. Informational: nothing in the
            // application decides anything from it, and there is deliberately
            // no maximum, because what to do about a package nobody can
            // deliver is a person's decision.
            $table->unsignedInteger('attempts')->default(0);

            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('out_for_delivery_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            // The fulfilment queue and its filters.
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('region');
            $table->index('tracking_reference');
        });

        // Every move, kept as evidence. When a customer says nobody came, this
        // is the record that answers -- and in a manual process there is no
        // courier API to ask instead.
        Schema::create('delivery_transitions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();

            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);

            // The controlled failure code, when the move was a failure.
            $table->string('reason_code', 40)->nullable();
            $table->string('note', 500)->nullable();

            $table->foreignId('caused_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['delivery_id', 'created_at']);
        });

        // Which address this order is going to, chosen at checkout. Null until
        // one is chosen, and only ever a pointer -- the delivery holds the copy
        // that matters, so a customer tidying their address book afterwards
        // cannot change where anything went.
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('delivery_address_id')->nullable()->after('winning_bid_id')
                ->constrained('addresses')->nullOnDelete();
        });

        // ---------------------------------------------------------------
        // Constraints. Repeated below the application, because a service can
        // be bypassed by a console command or a hand-run statement, and an
        // invariant that lives only in application code is a convention
        // rather than a guarantee.
        // ---------------------------------------------------------------

        DB::statement(<<<'SQL'
            ALTER TABLE deliveries
            ADD CONSTRAINT chk_deliveries_status CHECK (
                status IN (
                    'pending','preparing','ready_for_dispatch','dispatched',
                    'out_for_delivery','delivered','delivery_failed','cancelled'
                )
            ),
            ADD CONSTRAINT chk_deliveries_failure_reason CHECK (
                failure_reason IS NULL OR failure_reason IN (
                    'recipient_unavailable','incorrect_address','recipient_refused',
                    'phone_unreachable','delivery_area_issue','damaged_package','other'
                )
            )
        SQL);

        // A delivered package must say when, and a failed one must say why.
        // Without these a row could claim an outcome it has no evidence for.
        DB::statement(<<<'SQL'
            ALTER TABLE deliveries
            ADD CONSTRAINT chk_deliveries_delivered_has_time CHECK (
                status <> 'delivered' OR delivered_at IS NOT NULL
            ),
            ADD CONSTRAINT chk_deliveries_failed_has_reason CHECK (
                status <> 'delivery_failed' OR failure_reason IS NOT NULL
            )
        SQL);

        // Nothing may leave `pending` without somewhere to go. This is the
        // rule that stops a package being packed for an address nobody has
        // supplied, and it is a constraint rather than a check in a service
        // because it is the whole point of capturing an address at all.
        DB::statement(<<<'SQL'
            ALTER TABLE deliveries
            ADD CONSTRAINT chk_deliveries_have_address CHECK (
                status IN ('pending','cancelled')
                OR (
                    recipient_name IS NOT NULL
                    AND recipient_phone IS NOT NULL
                    AND address_line IS NOT NULL
                    AND city IS NOT NULL
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE delivery_transitions
            ADD CONSTRAINT chk_delivery_transitions_status CHECK (
                to_status IN (
                    'pending','preparing','ready_for_dispatch','dispatched',
                    'out_for_delivery','delivered','delivery_failed','cancelled'
                )
            )
        SQL);

        // Delivery history is evidence, and in a manual process it is the only
        // evidence there is.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER delivery_transitions_no_update
            BEFORE UPDATE ON delivery_transitions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Delivery history is append-only.';
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER delivery_transitions_no_delete
            BEFORE DELETE ON delivery_transitions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Delivery history is append-only.';
            END
        SQL);

        // The address freezes the moment anybody starts working on the
        // package. Before that it may still be supplied or corrected -- an
        // auction winner has to be able to say where their prize goes, and a
        // customer who mistyped a street should not need a new order. Once a
        // box is being packed for an address, that address is where it went.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER deliveries_frozen_address
            BEFORE UPDATE ON deliveries
            FOR EACH ROW
            BEGIN
                IF OLD.status <> 'pending' THEN
                    IF NOT (NEW.recipient_name <=> OLD.recipient_name)
                        OR NOT (NEW.recipient_phone <=> OLD.recipient_phone)
                        OR NOT (NEW.address_line <=> OLD.address_line)
                        OR NOT (NEW.area <=> OLD.area)
                        OR NOT (NEW.city <=> OLD.city)
                        OR NOT (NEW.region <=> OLD.region)
                        OR NOT (NEW.digital_address <=> OLD.digital_address)
                        OR NOT (NEW.landmark <=> OLD.landmark)
                    THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'A delivery address is frozen once the package is being handled.';
                    END IF;
                END IF;

                IF NOT (NEW.order_id <=> OLD.order_id) THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'A delivery belongs to the order it was created for.';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS deliveries_frozen_address');
        DB::unprepared('DROP TRIGGER IF EXISTS delivery_transitions_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS delivery_transitions_no_update');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_address_id');
        });

        Schema::dropIfExists('delivery_transitions');
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('addresses');
    }
};
