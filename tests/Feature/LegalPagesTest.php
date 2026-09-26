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
