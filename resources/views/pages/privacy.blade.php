@php
    // Draft for review. The factual content below is sourced from the
    // implementation, not from a template: every claim about what is collected,
    // who receives it and how long it is kept was checked against the schema,
    // the services and the migrations. Where the system cannot actually honour a
    // right, the page says so rather than promising it -- in particular account
    // deletion, which has no self-service path and would be refused at the
    // database for any customer with order, bid or referral history.
    //
    // NOT LEGAL ADVICE. The registered legal entity behind the trading name is
    // not recorded anywhere in the system, so it is not named below. That, the
    // controller's contact address, and the Data Protection Commission
    // registration must be supplied before publication. The placeholder tokens
    // are deliberately visible.
    $email = trim((string) settings()->getString('support_email', ''));
@endphp

<x-layouts.app title="Privacy"
               description="What personal data this store collects, why, who receives it, how long it is kept, and what you can ask us to do with it.">

    <x-page-header
        :title="'Privacy — '.config('app.name')"
        description="What we collect, why, who else sees it, and what you can ask us to do about it. Last updated 26 September 2026." />

    <div class="space-y-6">
        <x-card title="Who this site belongs to">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    This site is operated by <strong class="text-slate-900">[REGISTERED LEGAL ENTITY — TO BE SUPPLIED]</strong>,
                    trading as {{ config('app.name') }}. We are the data controller for the
                    personal data described here.
                </p>
                <p>
                    We are a Ghanaian company and this policy is written under the
                    <strong class="text-slate-900">Data Protection Act, 2012 (Act 843)</strong> and
                    the rules made under it by the Data Protection Commission of Ghana.
                </p>
                <p>
                    If you cannot reach us at the address on this page, complain to the
                    <strong class="text-slate-900">Data Protection Commission</strong>, whose
                    contact details are published by the Commission itself.
                </p>
            </div>
        </x-card>

        <x-card title="What we collect">
            <div class="space-y-4 text-sm text-slate-600">
                <p>
                    We collect only what the store needs to run. There are no trackers, no
                    advertising pixels and no third-party analytics anywhere on this site, so
                    the list below is the whole of it.
                </p>

                <div>
                    <h3 class="font-semibold text-slate-900">Your account</h3>
                    <p class="mt-1">
                        Your name, your phone number, and your email address if you give one.
                        The phone number is the one thing we require: it is how we reach you
                        about an order, and it is how you sign in. Email is optional and many
                        customers do not have one. We store your password only as a one-way
                        hash — we cannot read it, and neither can anyone who obtains our
                        database.
                    </p>
                </div>

                <div>
                    <h3 class="font-semibold text-slate-900">Verifying that you are you</h3>
                    <p class="mt-1">
                        When you sign up, change a password, or ask to recover a password, we
                        send a one-time code to your phone number or email. We store the code
                        hashed, the address it went to, when it expires, and how many times it
                        was tried. We never store the code in readable form.
                    </p>
                </div>

                <div>
                    <h3 class="font-semibold text-slate-900">Your orders and delivery</h3>
                    <p class="mt-1">
                        The name, phone number and delivery address you give us at checkout,
                        what you ordered, what it cost, and the state of the order. We keep a
                        copy of the delivery address on the delivery itself so that the
                        address a courier received cannot be quietly changed later.
                    </p>
                </div>

                <div>
                    <h3 class="font-semibold text-slate-900">Your payments</h3>
                    <p class="mt-1">
                        The payment provider's reference for your transaction, the amount, the
                        currency, whether it succeeded, and which payment method was used.
                        <strong class="text-slate-900">We never see or store your card number,
                        expiry date or security code.</strong> Card details are entered on your
                        bank's and the payment provider's own secure page; they never reach
                        our servers. We only learn that a payment succeeded from the provider
                        confirming it to us.
                    </p>
                </div>

                <div>
                    <h3 class="font-semibold text-slate-900">Credits, bids and the Store Wallet</h3>
                    <p class="mt-1">
                        If you buy credits or receive them, we keep a record of how many you
                        have, where each batch came from, what you paid for it, when it expires,
                        and every movement in and out. If you bid in an auction, we keep each
                        bid, the credits it committed, and when it was placed. If you lose an
                        auction, you may receive Store Wallet value, and that is recorded too.
                        This is the money record described under <em>How long we keep things</em>.
                    </p>
                </div>

                <div>
                    <h3 class="font-semibold text-slate-900">Referring a friend</h3>
                    <p class="mt-1">
                        If you arrive through somebody's referral link, we record that you did
                        and the code used. We do not record how the link was shared or by whom.
                    </p>
                </div>

                <div>
                    <h3 class="font-semibold text-slate-900">Messages and technical records</h3>
                    <p class="mt-1">
                        Messages we send you and whether you have read them; your sign-in
                        sessions, including your IP address and browser; a short-lived record
                        of failed sign-in and password-recovery attempts; and an internal log
                        of staff actions on the admin system, so that a change to a price, a
                        stock level or a refund can be traced to whoever made it.
                    </p>
                </div>
            </div>
        </x-card>

        <x-card title="Why we are allowed to hold it">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    We rely on three lawful bases, and on nothing else:
                </p>
                <ul class="list-disc space-y-2 pl-5">
                    <li>
                        <strong class="text-slate-900">To perform our contract with you</strong> —
                        for your account, your orders, your payments, your delivery, and the
                        credits attached to your bids. Without this data we cannot sell to you.
                    </li>
                    <li>
                        <strong class="text-slate-900">For our legitimate interests</strong> —
                        keeping the site secure, preventing fraud and abuse, recording who did
                        what on the admin system, and understanding whether the store works.
                        We balance this against your interests and do not do it where the
                        harm to you would outweigh the benefit to us.
                    </li>
                    <li>
                        <strong class="text-slate-900">With your consent</strong> — for
                        optional things like marketing messages. You can withdraw consent at
                        any time, and withdrawing it does not affect what we did while you
                        consented.
                    </li>
                </ul>
                <p>
                    We do not make decisions about you by automated profiling, and we do not
                    use your data to build an advertising profile of you.
                </p>
            </div>
        </x-card>

        <x-card title="Who else receives your data">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    A small number of companies process data on our behalf. Each is bound to
                    use it only for us.
                </p>
                <ul class="list-disc space-y-2 pl-5">
                    <li>
                        <strong class="text-slate-900">Paystack</strong> takes your payment and
                        confirms back to us whether it succeeded. Your card details go
                        straight to them and never to us.
                    </li>
                    <li>
                        <strong class="text-slate-900">Arkesel</strong>, our SMS provider,
                        receives your phone number and the text of a one-time code when you ask
                        for a code by SMS. This channel is not switched on at present.
                    </li>
                    <li>
                        <strong class="text-slate-900">Our email provider and web host</strong>
                        deliver your messages and run the site. They receive what the message
                        or the page requires and no more.
                    </li>
                </ul>
                <p class="rounded-lg bg-slate-50 p-3 text-slate-700">
                    <strong>We do not sell your data, rent it, or share it for anybody else's
                    advertising.</strong> We do not share it with data brokers. We will not
                    share it with a third party for their own purposes without telling you
                    first, except where the law requires it — and if we are ever legally
                    compelled to hand over data, we will tell you unless the law forbids us to.
                </p>
            </div>
        </x-card>

        <x-card title="In an auction, other customers cannot see who you are">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    Bids are shown as a position, never as a person. Nobody browsing an
                    auction can see which account placed a bid, how many credits you hold,
                    what you have bought, or anything about your wallet. The live auction
                    updates other people receive are limited to the auction, its current
                    highest bid in credits, the number of bids, and the closing time. Staff
                    can see bidder identities where an auction needs investigating, and we
                    record that access.
                </p>
            </div>
        </x-card>

        <x-card title="How long we keep things">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    Retention here is not a single number. It follows the record.
                </p>
                <div>
                    <h3 class="font-semibold text-slate-900">Money records are kept permanently</h3>
                    <p class="mt-1">
                        Payments, credit movements, bids, delivery records, refunds and
                        referrals are written as an append-only history. Entries are never
                        edited and never deleted — not by us, and not by anyone who reaches
                        the database, because the database itself refuses the change. If
                        something was recorded wrongly we add a correcting entry; the original
                        stays. This is deliberate: it is what makes the store's books
                        reconstructable, and it is why we can always show what happened to
                        your money. It also means this data is retained even after your
                        account is closed, for as long as we are required to keep the
                        financial record.
                    </p>
                </div>
                <div>
                    <h3 class="font-semibold text-slate-900">Order and delivery details</h3>
                    <p class="mt-1">
                        Kept for as long as the financial record for that order, because they
                        are the evidence of what was bought, by whom, and delivered where.
                    </p>
                </div>
                <div>
                    <h3 class="font-semibold text-slate-900">Sign-in sessions and failed attempts</h3>
                    <p class="mt-1">
                        Short-lived. A sign-in session ends after two hours of inactivity, and
                        the record of failed attempts is cleared quickly because it exists
                        only to slow down guessing your password.
                    </p>
                </div>
                <div>
                    <h3 class="font-semibold text-slate-900">One-time codes</h3>
                    <p class="mt-1">
                        Kept until they expire, then no longer useful to anyone. We do not need
                        to keep them afterwards.
                    </p>
                </div>
                <div>
                    <h3 class="font-semibold text-slate-900">Messages</h3>
                    <p class="mt-1">
                        Your in-site messages are kept while your account exists, so you can
                        read them.
                    </p>
                </div>
            </div>
        </x-card>

        <x-card title="What you can ask us to do">
            <div class="space-y-3 text-sm text-slate-600">
                <p>Under Act 843 you have the right to:</p>
                <ul class="list-disc space-y-2 pl-5">
                    <li>be told what personal data we hold about you, and get a copy;</li>
                    <li>have anything inaccurate corrected — most of this you can do yourself on your profile page;</li>
                    <li>ask us to delete data we no longer have a reason to keep;</li>
                    <li>object to, or withdraw consent for, use that rests on legitimate interests or consent;</li>
                    <li>complain to the Data Protection Commission.</li>
                </ul>

                <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-slate-700">
                    <p>
                        <strong>About deletion — please read.</strong> There is no
                        self-service delete button on this site, and we want to be straight
                        about why rather than imply otherwise. Our financial records are
                        append-only and are kept for the reasons above, so we cannot erase
                        the record of an order, a payment, a bid or a credit purchase even if
                        you ask — the store is built to refuse that, on purpose. What we
                        <em>can</em> do is close your account and delete the personal data
                        that carries no financial or legal weight: your saved addresses, your
                        message history, and the personal details on your profile. Email us
                        and we will do that and confirm it in writing. Money and transaction
                        records stay, and stay accurate, because the law requires us to be
                        able to account for them.
                    </p>
                </div>

                <p>
                    We answer within the period the Act allows, and we do not charge you for
                    it. If we decline a request we will say why.
                </p>
            </div>
        </x-card>

        <x-card title="How we keep it secure">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    Passwords and one-time codes are stored as one-way hashes, not as text.
                    Payment confirmation always happens by us calling the payment provider
                    directly — we never treat a browser's say-so as proof that money arrived.
                    Payment webhooks are checked against a cryptographic signature before we
                    act on them, so a forged call cannot move anybody's money. Sensitive
                    actions are authorised on the server, not in the page. Card details never
                    reach us at all.
                </p>
                <p>
                    No system is perfect. If a breach ever affects your data and creates a real
                    risk to you, we will tell you and the Commission as the law requires.
                </p>
            </div>
        </x-card>

        <x-card title="Children">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    This store is for adults. You must be at least 18 to hold an account, and
                    we do not knowingly collect data from anyone younger. If you believe a
                    child has an account with us, tell us and we will close it.
                </p>
            </div>
        </x-card>

        <x-card title="Changes to this policy">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    If we change what we collect or how we use it, we will update this page
                    and change the date at the top. If a change materially affects you, we
                    will tell you directly rather than only in this page.
                </p>
            </div>
        </x-card>

        <x-card title="Contact us">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    For any privacy question, to ask what we hold about you, or to have your
                    account closed:
                </p>
                <ul class="space-y-1 pl-5">
                    @if ($email !== '')
                        <li>Email: <a href="mailto:{{ $email }}" class="font-medium text-slate-900 underline">{{ $email }}</a></li>
                    @endif
                    <li>Postal address: <strong class="text-slate-900">[REGISTERED ADDRESS — TO BE SUPPLIED]</strong></li>
                </ul>
                @if ($email === '')
                    <p class="text-slate-500">
                        Our email address has not been published on this site yet. Use the
                        <a href="{{ route('contact') }}" class="underline">contact page</a> in the meantime.
                    </p>
                @endif
            </div>
        </x-card>
    </div>
</x-layouts.app>
