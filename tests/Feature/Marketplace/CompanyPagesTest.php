<?php

declare(strict_types=1);

/*
 * The company pages, the footer, and where the general explanation lives.
 *
 * Two rules are held here beyond "it renders". These pages may say only what the
 * platform demonstrably is and does -- no invented stories, numbers, policies or
 * promises -- and the footer may link only to pages that exist. A link to a page
 * that is not there is a broken promise printed on every page of the site.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();
});

// -------------------------------------------------------------- They exist

it('serves each company page to a guest with its own title', function (string $route, string $title): void {
    $this->get(route($route))
        ->assertOk()
        ->assertSee('<title>'.$title.'</title>', false);
})->with([
    'about' => ['about', 'About us'],
    'contact' => ['contact', 'Contact us'],
    'faqs' => ['faqs', 'FAQs'],
]);

it('gives each company page a description for search and sharing', function (string $route): void {
    $this->get(route($route))
        ->assertOk()
        ->assertSee('<meta name="description"', false)
        ->assertSee('<link rel="canonical"', false);
})->with(['about', 'contact', 'faqs']);

// ---------------------------------------------------------------- The menu

/*
 * The header's menu is Alpine, and Alpine ships inside Livewire's JavaScript.
 * Livewire only injects that onto a page that renders a Livewire component, so
 * a plain Blade page got the header markup with nothing to run it: the menu was
 * dead on How It Works (before this release) and on the pages added with it.
 *
 * A test that asserted the menu "opens" would need a browser. What can be held
 * without one is the cause: every page that carries the header must also carry
 * the script that runs it, and it must carry it exactly once.
 */
it('loads the script that runs the header menu on every public page', function (string $route): void {
    $html = $this->get(route($route))->assertOk()->getContent();

    // The header is Alpine markup...
    expect($html)->toContain('<header x-data=')
        // ...so the bundle that contains Alpine has to be on the page.
        ->and(preg_match('#/livewire-[^/"]+/livewire(\.min)?\.js#', $html))->toBe(1);
})->with([
    // The plain Blade pages: the ones that were broken.
    'how-it-works',
    'about',
    'contact',
    'faqs',
    // And the Livewire pages, which must not regress.
    'home',
    'products.index',
    'auctions.index',
]);

it('does not load the script twice on a page that is also a Livewire component', function (): void {
    // Including the assets by hand must not make Livewire inject them again.
    $html = $this->get(route('products.index'))->assertOk()->getContent();

    expect(preg_match_all('#/livewire-[^/"]+/livewire(\.min)?\.js#', $html))->toBe(1)
        ->and(substr_count($html, '<!-- Livewire Styles -->'))->toBe(1);
});

it('carries the hiding rule the mobile drawer depends on', function (): void {
    // The drawer is `x-show` + `x-cloak`. The [x-cloak] rule ships with
    // Livewire's styles, and without it the drawer is visible before Alpine
    // starts -- or, on a page where Alpine never starts, permanently.
    $this->get(route('faqs'))
        ->assertOk()
        ->assertSee('[x-cloak]', false);
});

// ------------------------------------------------------------------ Footer

it('links the company pages from the footer on every public page', function (string $route): void {
    $this->get(route($route))
        ->assertOk()
        ->assertSee(route('about'), false)
        ->assertSee(route('contact'), false)
        ->assertSee(route('faqs'), false)
        // The shopping links stay too: the footer adds to the navigation.
        ->assertSee(route('products.index'), false)
        ->assertSee(route('auctions.index'), false)
        ->assertSee(route('how-it-works'), false);
})->with(['home', 'products.index', 'auctions.index', 'how-it-works', 'about', 'contact', 'faqs']);

it('does not link a page that does not exist yet', function (): void {
    // Blog and the legal pages need content and a legal author. Until they
    // exist a footer link to one is a 404 on every page.
    $html = $this->get(route('home'))->assertOk()->getContent();

    foreach (['/blog', '/privacy', '/cookies', '/terms'] as $missing) {
        expect($html)->not->toContain('href="'.url($missing).'"');
    }
});

it('keeps the main navigation, rather than moving it into the footer', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('aria-label="Primary"', false)
        ->assertSee('aria-label="Footer: company"', false);
});

// ----------------------------------------------------------------- Contact

it('says so plainly when no contact details have been published', function (): void {
    $this->get(route('contact'))
        ->assertOk()
        ->assertSee('Contact details are not published yet')
        // There is no inbox behind this page, so it does not offer a form.
        ->assertDontSee('<form', false)
        ->assertDontSee('mailto:', false)
        ->assertDontSee('tel:', false);
});

it('shows the contact details an administrator has set', function (): void {
    settings()->set('support_email', 'help@example.test');
    settings()->set('support_phone', '024 412 3456');

    $this->get(route('contact'))
        ->assertOk()
        ->assertDontSee('Contact details are not published yet')
        ->assertSee('mailto:help@example.test', false)
        ->assertSee('help@example.test')
        ->assertSee('024 412 3456')
        // The link wants digits, not the spaces somebody typed it with.
        ->assertSee('tel:0244123456', false);
});

it('shows only the detail that has been set', function (): void {
    settings()->set('support_email', 'help@example.test');

    $this->get(route('contact'))
        ->assertOk()
        ->assertSee('mailto:help@example.test', false)
        // No phone was set, so none is shown: not a placeholder, not a blank row.
        ->assertDontSee('tel:', false);
});

it('escapes what an administrator typed into a contact setting', function (): void {
    settings()->set('support_email', '"><script>alert(1)</script>');

    $this->get(route('contact'))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false);
});

// ------------------------------------------------------------------- About

it('makes no claim it cannot back', function (): void {
    // No stories, numbers, dates, awards or savings: none are recorded
    // anywhere, and an About page is where a made-up one would read as the
    // most authoritative thing on the site.
    $page = $this->get(route('about'))->assertOk();

    foreach (['Success Stories', 'testimonial', 'since 20', 'founded', 'award', 'customers served', 'guaranteed', 'risk-free', 'always cheaper'] as $claim) {
        $page->assertDontSee($claim, false);
    }
});

it('says the true things about how the platform works', function (): void {
    $this->get(route('about'))
        ->assertOk()
        ->assertSee('A store first')
        ->assertSee('We hold the stock ourselves')
        ->assertSee('Credits are not money')
        // The phrase stops before the line break the source wraps at.
        ->assertSee('consumed whether or not you');
});

// -------------------------------------------------------------------- FAQs

it('answers the questions a customer actually has', function (): void {
    $this->get(route('faqs'))
        ->assertOk()
        ->assertSee('What are credits?')
        ->assertSee('Do I get my credits back if I lose?')
        ->assertSee('What decides who wins an auction?')
        ->assertSee('What is Store Wallet, and where can I use it?')
        ->assertSee('How is my order delivered?');
});

it('never offers credits back and says so first', function (): void {
    $this->get(route('faqs'))
        ->assertOk()
        ->assertSee('Credits are consumed the moment a bid is accepted')
        ->assertSee('That is not a refund of credits');
});

it('promises no refund, return, saving or delivery time', function (): void {
    // There is no returns process to describe and no delivery time the
    // platform controls. A question about either is left out rather than
    // answered with a guess.
    $page = $this->get(route('faqs'))->assertOk();

    foreach (['money back', 'money-back', 'return policy', 'returns policy', 'guaranteed', 'risk-free', 'free delivery', 'same day', 'within 24', 'next day'] as $promise) {
        $page->assertDontSee($promise, false);
    }
});

it('uses native disclosure elements, so it works without JavaScript', function (): void {
    $html = $this->get(route('faqs'))->assertOk()->getContent();

    expect(substr_count($html, '<details'))->toBeGreaterThanOrEqual(8)
        ->and(substr_count($html, '<summary'))->toBe(substr_count($html, '<details'));
});

// ----------------------------------------------------------- How It Works

it('leads with the shop, because this is a store first', function (): void {
    $html = $this->get(route('how-it-works'))->assertOk()->getContent();

    expect(strpos($html, 'Shopping in the store'))
        ->toBeLessThan(strpos($html, 'Auctions: competing with credits'));
});

it('says a cart holds nothing and an unpaid order expires', function (): void {
    $this->get(route('how-it-works'))
        ->assertOk()
        ->assertSee('A cart holds nothing')
        ->assertSee('expires');
});

it('explains the Store Wallet without ever offering credits back', function (): void {
    // Both halves together, because either alone misleads: the credits stay
    // consumed AND Store Wallet value exists.
    $this->get(route('how-it-works'))
        ->assertOk()
        ->assertSee('If you bid and do not get the product')
        ->assertSee('Bid credits stay consumed however an auction ends')
        ->assertSee('Store Wallet is not credits, and it is not a refund of credits')
        ->assertSee('never covers a whole order')
        ->assertSee('cannot be turned back into credits')
        // Free credits are worth nothing, and the page says so.
        ->assertSee('Credits that cost nothing');
});

it('keeps the credit-consumption warning first among the bidding steps', function (): void {
    $html = $this->get(route('how-it-works'))->assertOk()->getContent();

    expect(strpos($html, 'Credits you bid are gone, win or lose.'))
        ->toBeLessThan(strpos($html, 'Buy credits'));
});

it('holds the trust statements that used to be cards on the front page', function (): void {
    $this->get(route('how-it-works'))
        ->assertOk()
        ->assertSee('What you can rely on')
        ->assertSee('The server decides')
        ->assertSee('Payments are verified')
        ->assertSee('Delivered by hand');
});

// ---------------------------------------------------------------- Front page

it('no longer repeats the explanation on the front page', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('Timing and results are decided on our servers')
        ->assertDontSee('The server decides')
        ->assertDontSee('Payments are verified')
        ->assertDontSee('Delivered by hand');
});

it('still gives the front page its shop-first identity and its disclosure', function (): void {
    // Consolidating the education must not take away what a customer needs at
    // the moment of decision: the credit warning stays where a bidder sees it.
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('The shop, first.')
        ->assertSee('Newly added')
        ->assertSee('Credits you bid are consumed straight away and are not returned if you do not win.')
        ->assertSee(route('how-it-works'), false);
});
