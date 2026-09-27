<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;

/*
 * The three legal pages, and the promises they make.
 *
 * These pages are written from the implementation: every claim about what is
 * collected, who receives it and who may see it was checked against the schema
 * and the gateways. That is what makes them safe to publish, and it is also
 * what makes them fragile -- the day the implementation changes, the wording
 * that described the old behaviour becomes a lie told to customers.
 *
 * So these tests do not check that the pages look nice. They check that the
 * claims most likely to be quietly edited away, or to become false later, are
 * still on the page. A failure here means either the wording was changed
 * carelessly, or the code was changed and the pages were not updated with it.
 * Both are worth stopping for.
 *
 * DRAFT: the pages are unreviewed and name no registered entity. See
 * docs/LEGAL_PAGES_DRAFT_NOTES.md.
 */

/**
 * The visible text of a page, whitespace-collapsed.
 *
 * These pages are prose, and prose gets re-wrapped. Asserting on the raw HTML
 * would break every time somebody tidied a paragraph, so the text is
 * normalised first and the assertion is about what a reader actually reads.
 * Script and style content is dropped so an assertion cannot be satisfied by
 * something that only appears in a script.
 */
function legalText(TestResponse $response): string
{
    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $response->getContent());

    return trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $html)));
}

beforeEach(function (): void {
    seedRoles();
    // The operator's registered name and address are settings, so the settings
    // have to exist for these pages to render at all.
    seedSettings();
});

it('links every legal page from the footer', function (string $route, string $label): void {
    // The footer's own rule: only pages that exist are linked, because a dead
    // link is a broken promise printed on every page of the site.
    $this->get(route('home'))
        ->assertOk()
        ->assertSee(route($route))
        ->assertSee($label);
})->with([
    ['privacy', 'Privacy notice'],
    ['terms', 'Terms of use'],
    ['cookies', 'Cookies'],
]);

it('never says we store card details', function (): void {
    // True today: card entry happens on Paystack's hosted page, and no column
    // anywhere holds a PAN, expiry or CVV. If card handling is ever added to
    // this codebase, this page becomes false and must change in the same change.
    expect(legalText($this->get(route('privacy'))->assertOk()))
        ->toContain('We never see or store your card number');
});

it('does not promise a deletion it cannot perform', function (): void {
    // The database refuses to delete a user who has order, bid, delivery,
    // refund or referral history, and the credit, cash and Store Wallet ledgers
    // are append-only to the point of having triggers that block deletion. A
    // wording change that quietly dropped this caveat would advertise a right
    // the store cannot honour.
    $text = legalText($this->get(route('privacy'))->assertOk());

    expect($text)
        ->toContain('There is no self-service delete button on this site')
        ->toContain('we cannot erase the record of an order')
        ->toContain('append-only');
});

it('says plainly that credits are not money', function (): void {
    // The most argued-about claim in the product, and the one the store's
    // design most depends on customers believing.
    expect(legalText($this->get(route('terms'))->assertOk()))
        ->toContain('Credits are not money');
});

it('states that committed bidding credits are spent permanently', function (): void {
    $text = legalText($this->get(route('terms'))->assertOk());

    expect($text)
        ->toContain('Credits committed to a bid are spent, permanently')
        ->toContain('Losing an auction does not return them');
});

it('claims no third-party tracking, which the layout must not contradict', function (): void {
    // The cookie notice leans hard on there being no trackers, no embeds and no
    // analytics, and that is currently true of resources/. If somebody adds a
    // pixel, an analytics snippet or a third-party embed, the promise is broken
    // and a consent mechanism becomes required -- so this test is the tripwire.
    $response = $this->get(route('cookies'))->assertOk();

    expect(legalText($response))->toContain('no advertising, no analytics, no tracking');

    // And the markup itself must not load anything from a host other than ours.
    // Relative URLs are ours by definition. An absolute URL is only acceptable
    // when it points back at this application -- the Vite bundle and the
    // Livewire runtime are both absolute and both fine -- so the test is about
    // the host, not about whether a protocol was written down.
    $body = $response->getContent();

    preg_match_all(
        '/<(script|img|iframe)\b[^>]*\b(?:src|data-src)\s*=\s*["\']([^"\']+)["\']/i',
        $body,
        $matches
    );

    $ourHost = parse_url((string) config('app.url'), PHP_URL_HOST);

    $foreign = array_values(array_filter(
        $matches[2],
        fn (string $src): bool => str_starts_with($src, 'http')
            && parse_url($src, PHP_URL_HOST) !== $ourHost
    ));

    expect($foreign)->toBe([], 'The cookie notice promises no third-party requests, but the page loads one.');
});

it('carries a last-updated date on every page', function (): void {
    // A policy with no date cannot be shown to have been kept current.
    expect(legalText($this->get(route('privacy'))->assertOk()))->toContain('Last updated');
    expect(legalText($this->get(route('terms'))->assertOk()))->toContain('Last updated');
    expect(legalText($this->get(route('cookies'))->assertOk()))->toContain('Last updated');
});

/*
 * The registered legal entity, and the fact that it is read from settings.
 *
 * It used to be a placeholder typed into the two templates, which meant
 * publishing needed a code change and a deploy, and the notice and the terms
 * could each have been edited to name a different company. Both are now
 * settings, and these tests are what stop them going back.
 */

it('names the registered entity once it is set, and the same one on both pages', function (): void {
    settings()->setMany([
        'legal_entity_name' => 'Kwaku Mensah Trading Ltd',
        'legal_entity_address' => '14 Independence Avenue, Accra',
    ]);

    foreach (['privacy', 'terms'] as $page) {
        $text = legalText($this->get(route($page))->assertOk());

        expect($text)->toContain('Kwaku Mensah Trading Ltd');
        expect($text)->toContain('14 Independence Avenue, Accra');
        // A page carrying the real name must not also carry the draft marker,
        // or one is being published by accident and the other on purpose.
        expect($text)->not->toContain('NOT YET SUPPLIED');
    }
});

it('escapes the registered entity rather than trusting it as markup', function (): void {
    // It arrives as operator-supplied settings text, so it is the one piece of
    // prose on these pages that was not written by us. It is printed through
    // Blade, which escapes it, and this is the test that says so out loud --
    // a page whose controller name is a settings value is a page with a stored
    // injection point on it if that ever stops being true.
    settings()->setMany(['legal_entity_name' => 'Ampersand & Co <script>alert(1)</script> Ltd']);

    $html = $this->get(route('privacy'))->assertOk()->getContent();

    // The payload must be inert: the angle brackets escaped, and the ampersand
    // escaped rather than dropped, so the rendered text is still correct.
    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->toContain('Ampersand &amp; Co')
        ->and($html)->not->toContain('<script>alert(1)</script>');
});

it('shows a visible draft marker while the entity is unset, never a blank', function (): void {
    settings()->setMany(['legal_entity_name' => null, 'legal_entity_address' => null]);

    foreach (['privacy', 'terms'] as $page) {
        $text = legalText($this->get(route($page))->assertOk());

        // A blank in the middle of a well-formatted notice reads as finished.
        // The gap has to be visible on the page, and it has to be on both.
        expect($text)->toContain('NOT YET SUPPLIED');
    }
});

it('never falls back to the trading name as the controller', function (): void {
    // "As-Is-Commerce" is a trading name. Printing it where Act 843 requires
    // the registered entity would make the notice confidently wrong, which is
    // worse than leaving the marker visible.
    settings()->setMany(['legal_entity_name' => null, 'legal_entity_address' => null]);

    $text = legalText($this->get(route('privacy'))->assertOk());

    expect($text)->toContain('NOT YET SUPPLIED');
});

/*
 * The Data Protection Commission registration.
 *
 * The setting exists and the seeder described it as shown on the privacy
 * notice, but nothing rendered it -- so the documentation was describing a
 * disclosure the page did not make. It is a factual statement about the
 * operator, not a rule we get to choose, which is why it is driven by whether
 * the number exists rather than by anything we decided.
 */

it('does not print a registration number the operator does not hold', function (): void {
    settings()->set('dpc_registration', '');

    $this->get('/privacy')->assertOk()
        ->assertDontSee('under registration number');
});

it('prints the registration number once it is supplied', function (): void {
    settings()->set('dpc_registration', 'DPC/GH/2026/0001');

    $this->get('/privacy')->assertOk()
        ->assertSee('under registration number')
        ->assertSee('DPC/GH/2026/0001');
});

it('keeps the terms free of a data protection registration number', function (): void {
    // It belongs to the privacy notice only. A number appearing in the terms as
    // well would imply a registration that covers more than data processing.
    settings()->set('dpc_registration', 'DPC/GH/2026/0001');

    $this->get('/terms')->assertOk()
        ->assertDontSee('DPC/GH/2026/0001');
});
