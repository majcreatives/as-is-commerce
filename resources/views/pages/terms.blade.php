@php
    // Draft for review. The commercial terms below follow the implemented
    // rules, not the reverse: highest total credits committed wins, consumed
    // bidding credits are consumed permanently, Buy Now ends the auction, and
    // Store Wallet is a separate cash-value balance that is not a credit
    // refund. The credit-not-money distinction is the single most important
    // clause on the page and is the one most likely to be argued about, so it
    // is stated plainly rather than softened.
    //
    // NOT LEGAL ADVICE. The registered legal entity, its address, and the
    // governing-law clause need a lawyer's eye. The registered entity is not
    // recorded anywhere in the system, so it is not named.
@endphp

<x-layouts.app title="Terms"
               description="The rules for using this store: accounts, credits, auctions, payments, delivery, and the limits of what we can promise.">

    <x-page-header
        :title="'Terms of Use — '.config('app.name')"
        description="The rules of the store. Last updated 26 September 2026." />

    <div class="space-y-6">
        <x-card title="1. About these terms">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    These terms govern your use of {{ config('app.name') }}, operated by
                    <strong class="text-slate-900">[REGISTERED LEGAL ENTITY — TO BE SUPPLIED]</strong>.
                    By creating an account or placing an order you accept them. If you do not
                    accept them, do not use the store.
                </p>
                <p>
                    Some pages carry their own rules — how an auction runs is set out in
                    <em>How It Works</em> — and those form part of these terms.
                </p>
                <p>
                    These terms are governed by the laws of the Republic of Ghana, and the
                    courts of Ghana have jurisdiction over any dispute.
                </p>
            </div>
        </x-card>

        <x-card title="2. Who may use the store">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    You must be at least 18 years old. One person, one account: an account
                    may not be shared or operated on somebody else's behalf. Give us a phone
                    number you control, and keep it current — we use it for order and
                    delivery contact, and losing access to it can stop you signing in.
                </p>
                <p>
                    Everything you tell us must be true and current. Do not register on
                    behalf of another person, and do not use the store for anything unlawful
                    or to harm anybody.
                </p>
            </div>
        </x-card>

        <x-card title="3. What we sell">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    We are the seller. We hold the stock ourselves and we do not act as an
                    agent for any third-party vendor; when you buy from this store you are
                    dealing with us, not with a marketplace of independent sellers. We may
                    change what we stock at any time, and a listing can be withdrawn before
                    it is paid for.
                </p>
            </div>
        </x-card>

        <x-card title="4. Credits — what they are, and what they are not">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    Credits are how you bid in an auction. You buy them for money, you spend
                    them on bids, and that is what they are for.
                </p>
                <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-slate-700">
                    <strong>Read this part carefully, because it is the most common
                    misunderstanding about this store.</strong> Credits are
                    <strong>not money</strong>. They cannot be withdrawn, transferred, sold, or
                    redeemed for cash. They cannot be spent as a discount on anything, and they
                    do not buy goods directly. Credits are not a currency, they have no
                    universal cash value, and holding them does not give you a claim on the
                    business. Prices in the shop are in Ghanaian cedis and are paid in cedis.
                </p>
                <p>
                    When you buy credits, the amount you paid and the number of credits you
                    received are recorded together and kept as a permanent record. That record
                    is what determines the cash value of those particular credits if they are
                    ever compensated, and it is not recalculated from later prices.
                </p>
                <p>
                    Credits bought with money are also subject to an expiry date shown on the
                    package. Promotional or complimentary credits may expire sooner. Expired
                    credits are written off and cannot be recovered.
                </p>
            </div>
        </x-card>

        <x-card title="5. Auctions">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    An auction runs for a fixed period set before it opens, and the time is
                    decided by our server, not by your device. Your browser countdown is for
                    comfort only — the closing time that counts is the one on our server.
                </p>
                <p>
                    You choose how many credits each bid commits. Your <strong>position in
                    the auction is the total of all the credits you have committed</strong>,
                    not your single largest bid. The server works out the bid that puts you
                    one step ahead of the leader, and the customer with the highest committed
                    total when the auction closes wins. If two customers are level on
                    credits, the earlier bid takes it.
                </p>
                <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-slate-700">
                    <strong>Credits committed to a bid are spent, permanently.</strong> They
                    are consumed whether or not you win. Losing an auction does not return
                    them. This is not a charge we can reverse, so please bid only what you
                    genuinely intend to spend, and do not bid in an auction you cannot afford
                    to lose.
                </p>
                <p>
                    You may bid on an auction only if you are eligible to: your account must
                    be in good standing, you must not be somebody who works for us, and you
                    must have enough available credits. Whether a bid succeeds is decided by
                    our server at the moment you submit it — not by what your screen shows,
                    and not by who pressed the button first.
                </p>
                <p>
                    A bid that reaches the point where the total of all bids meets an
                    auction's closing target ends the auction early, and the leader at that
                    moment wins for the smaller total they have committed.
                </p>
                <p>
                    If the winning customer's payment then fails or is not completed within
                    the time allowed, the auction may be forfeited and we may offer the
                    product again or cancel it. We will tell the parties involved.
                </p>
            </div>
        </x-card>

        <x-card title="6. Store Wallet — and what it is not">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    If an auction ends with another customer buying the product, a losing
                    bidder whose committed credits were paid-for credits may be credited
                    <strong>Store Wallet value</strong> — a separate balance held in cedis.
                </p>
                <p>
                    Store Wallet is not a refund of the credits you bid with. Your bidding
                    credits remain spent; what you may receive is a separate cash-value
                    balance. It is not a bank account, not a withdrawal, and not a guaranteed
                    payment. It can be used to pay towards eligible purchases in the shop, and
                    it is released back to you if an order it was applied to is cancelled or
                    expires. It cannot be converted into credits or into cash, and it is not
                    paid out.
                </p>
                <p>
                    No Store Wallet value is issued to the winner of an auction, to a customer
                    who bought the product outright, or where an auction ended for a reason
                    connected to nobody's purchase.
                </p>
            </div>
        </x-card>

        <x-card title="7. Buy Now">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    Some products can be bought outright without waiting for an auction. A
                    successful Buy Now ends the auction immediately. Nobody standing in the
                    auction becomes the winner, and credits committed in that auction are not
                    returned.
                </p>
            </div>
        </x-card>

        <x-card title="8. Prices, payment and when we get paid">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    All prices are in Ghanaian cedis. A price shown is the price of that
                    product, plus any delivery charge shown at checkout. We may correct a
                    price that was published in error; if you have already paid, the price you
                    paid is the price.
                </p>
                <p>
                    Payment is by card through Paystack, on Paystack's secure page. <strong>We
                    never see or store your card details.</strong> An order is paid only when
                    Paystack confirms to us, on our server, that money has actually arrived.
                    Your bank's confirmation screen, your browser, or an emailed receipt is not
                    proof of payment, and an order is not paid because a page said so.
                </p>
                <p>
                    If you do not pay within the time allowed, the order is cancelled and any
                    stock held for it is released for sale to others.
                </p>
                <p>
                    Where a payment succeeds but we cannot deliver the goods — because
                    another customer legitimately bought the single unit first, or the order
                    expired during payment — the payment stands, we record it as paid, and we
                    put it in front of our staff to sort out. We do not quietly cancel a
                    payment that reached us.
                </p>
            </div>
        </x-card>

        <x-card title="9. Delivery">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    We deliver by hand. We do not yet have automated courier tracking, so
                    delivery dates are estimates rather than guarantees, and we will tell you
                    if something goes wrong rather than let it sit.
                </p>
                <p>
                    Delivery is to the address you give us, and someone must be able to
                    receive it. If a delivery fails because the address was wrong, unreachable,
                    or refused, we may charge you the cost of the attempt and any return.
                    Please check your address and number carefully — we cannot redirect a
                    delivery that has already left.
                </p>
            </div>
        </x-card>

        <x-card title="10. Referrals">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    You can refer a friend. A referral link that gets somebody to register,
                    click, or look at a product earns you nothing on its own. A reward is only
                    paid when a referred customer completes a real purchase that we have
                    verified and that we can deliver against.
                </p>
                <p>
                    One person may be referred by only one referrer. Referring yourself is
                    refused. A reward is paid once per referred customer, at the rate that
                    applies when it is paid, and the rate can be changed. We may reverse a
                    referral we can show was not genuine, and we may change or withdraw the
                    referral programme — a reward already paid is not taken back.
                </p>
            </div>
        </x-card>

        <x-card title="11. Acceptable use">
            <div class="space-y-3 text-sm text-slate-600">
                <p>You agree not to:</p>
                <ul class="list-disc space-y-2 pl-5">
                    <li>break the law, or infringe anybody else's rights;</li>
                    <li>interfere with the store, probe it for weakness, or attempt to access anything that is not yours;</li>
                    <li>place bids or orders you cannot pay for, or with payment instruments that are not yours;</li>
                    <li>create accounts to work around a cap, a queue or a rule — including referral rewards;</li>
                    <li>resell our stock without a written agreement with us;</li>
                    <li>harass, impersonate or misrepresent yourself or anybody else.</li>
                </ul>
                <p>
                    Staff and contractors of the business may not bid in customer auctions.
                </p>
                <p>
                    We may suspend or close an account that breaches these terms, or that we
                    reasonably believe is being used fraudulently. Where money is owed, closing
                    an account does not erase it.
                </p>
            </div>
        </x-card>

        <x-card title="12. What we promise, and what we do not">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    We will do our genuine best to keep the store available, to keep your
                    orders accurate, and to keep your money and credits right. We do not
                    promise that the site will never be down or slow, that a listing will
                    always be in stock, or that an auction will reach any particular price.
                </p>
                <p>
                    Nothing in these terms excludes or limits our liability for death or
                    personal injury caused by negligence, for fraud, or for anything else that
                    cannot lawfully be excluded. Subject to that, our total liability to you
                    is limited to the amount you actually paid us for the order or credits in
                    question, and we are not liable for indirect or consequential loss such as
                    lost profit or lost opportunity.
                </p>
                <p>
                    Our website content is provided for information. Photographs, descriptions
                    and availability can still be wrong despite our care; if something is
                    materially misdescribed, tell us and we will put it right.
                </p>
            </div>
        </x-card>

        <x-card title="13. Complaints and disputes">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    If something is wrong, tell us through the
                    <a href="{{ route('contact') }}" class="underline">contact page</a> and we
                    will look at it. We would much rather fix a problem than defend a decision.
                    Where a dispute cannot be settled between us, the courts of Ghana will
                    decide it, and Ghanaian law applies.
                </p>
            </div>
        </x-card>

        <x-card title="14. Changes to these terms">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    We may change these terms. The date at the top always shows the current
                    version. Changes apply from when we publish them and do not retroactively
                    change an order, bid or auction already under way — those run on the rules
                    that were in force when they started.
                </p>
            </div>
        </x-card>

        <x-card title="15. Contact">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    Questions about these terms go to
                    <a href="{{ route('contact') }}" class="underline">our contact page</a>.
                </p>
                <p>
                    Postal address:
                    <strong class="text-slate-900">[REGISTERED ADDRESS — TO BE SUPPLIED]</strong>
                </p>
            </div>
        </x-card>
    </div>
</x-layouts.app>
