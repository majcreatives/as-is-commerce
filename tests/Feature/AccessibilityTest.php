<?php

declare(strict_types=1);

use App\Livewire\Wallet\WalletOverview;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/*
 * The accessibility of the parts a form or a navigation is built from.
 *
 * WHY THIS IS A SEPARATE FILE FROM SeoAndAccessibilityTest.
 *
 * That file asks whether a page can be found and whether its heading structure
 * makes sense. This one asks whether the controls on it can be operated, and
 * the two fail in completely different ways. A page with a perfect heading tree
 * and a password field whose validation message is never announced has still
 * locked somebody out of their own account.
 *
 * WHY THE SHARED COMPONENTS ARE THE TARGET.
 *
 * Nearly every form in the product is assembled from x-field, x-input and
 * x-error. Fixing one of them fixes all 32 forms at once, so the tests below are
 * written against the components rather than against individual pages: a fix
 * applied to the wrong layer would otherwise satisfy a page test while leaving
 * the other 31 broken, and would look like progress.
 *
 * The invariants are expressed as relationships rather than as literals. "The
 * control points at its own error" survives the wording of the message being
 * changed; a test that hard-coded an id would only be checking that a string is
 * still present.
 */

/**
 * Render a form field the way a page does: a control inside the slot, an error,
 * and optionally a hint.
 *
 * The control is given :error as well, which is the convention the product's
 * forms already follow -- a field is told whether it has a message so it can
 * render it, and its control is told so it can be marked invalid. See the note
 * on role="alert" below for why the message is announced even when a control is
 * not given that second flag.
 */
function renderField(array $data = [], bool $withHint = false): string
{
    $hint = $withHint ? ' hint="We will text you a code"' : '';

    return Blade::render(
        <<<BLADE
            <x-field label="Phone number" name="phone" :error="\$message"{$hint}>
                <x-input id="phone" :error="\$failed" />
            </x-field>
        BLADE,
        $data + ['message' => null, 'failed' => false],
    );
}

function renderPasswordField(string $message): string
{
    return Blade::render(
        <<<'BLADE'
            <x-field label="Password" name="password" :error="$message">
                <x-password-input id="password" :error="$failed" />
            </x-field>
        BLADE,
        ['message' => $message, 'failed' => $message !== null],
    );
}

/**
 * Render a field the way most of the admin forms are written: the message is
 * passed to the field, and the control inside is not told about it.
 */
function renderFieldWithUnwiredControl(string $message): string
{
    return Blade::render(
        <<<'BLADE'
            <x-field label="City or town" name="form.city" :error="$message">
                <input type="text" id="form.city" />
            </x-field>
        BLADE,
        ['message' => $message],
    );
}

/*
 * Form errors.
 *
 * The finding these tests pin down: every validation message in the product was
 * rendered as red text and nothing more. The control beside it was not marked
 * invalid and did not point at the message, so the one thing a screen reader
 * user needed most -- why the form refused them -- was only available by
 * navigating backwards through the page to find a red paragraph. The colour was
 * carrying the entire meaning.
 */
it('marks a control invalid and points it at its own message', function (): void {
    $html = renderField(['message' => 'That phone number is not recognised.', 'failed' => true]);

    // The message has an id a control can point at...
    expect($html)->toContain('id="phone-error"');

    // ...the control points at it, so it is read when focus lands there...
    expect($html)->toContain('aria-describedby="phone-error"');

    // ...and the control is marked invalid rather than merely coloured.
    expect($html)->toContain('aria-invalid="true"');
});

it('announces a message that appears after a rejected submit', function (): void {
    // The half that covers every form in the product, including the ones whose
    // control cannot be marked invalid.
    //
    // 33 of the 38 controls in the product are told about their error by nobody:
    // the admin forms, the address book, and every sign-in and registration field
    // pass the message to x-field but not to the control inside it, so the
    // control cannot carry aria-invalid. Putting role="alert" on the message
    // itself means the rejection is announced the moment Livewire swaps it into
    // the DOM in all of them, rather than only in the forms that happen to pass
    // the error down twice.
    $html = renderFieldWithUnwiredControl('Required.');

    expect($html)->toMatch('/role="alert"[^>]*>\s*Required\./');
});

it('leaves a control it cannot mark invalid with no reference to a message', function (): void {
    // The control must not claim to be described by something it is not.
    // Asserted separately from the test above because "announced" and
    // "described" are different guarantees and it is possible to have one
    // without the other.
    $html = renderFieldWithUnwiredControl('Required.');

    expect($html)
        ->toContain('id="form-city-error"')
        ->not->toContain('aria-describedby')
        ->not->toContain('aria-invalid');
});

it('wires a password field the same way as a text field', function (): void {
    // A password rejected by validation is the worst case of all, because the
    // message is usually the only signal that the reason was not "too short".
    $html = renderPasswordField('That password is incorrect.');

    expect($html)
        ->toContain('id="password-error"')
        ->toContain('aria-describedby="password-error"')
        ->toContain('aria-invalid="true"')
        ->toContain('role="alert"');
});

it('does not mark a control invalid when there is nothing wrong with it', function (): void {
    // The inverse failure, and the reason both attributes are conditional.
    // Reporting a field as invalid when it is valid trains somebody to ignore
    // the signal, and a describedby pointing at an element that is not rendered
    // is a dangling reference assistive technology has to recover from.
    $html = renderField();

    expect($html)
        ->not->toContain('aria-invalid')
        ->not->toContain('aria-describedby')
        ->not->toContain('role="alert"')
        ->not->toContain('phone-error');
});

it('gives a dotted field name an id that can be referenced', function (): void {
    // The address book binds "form.city", and a dot is not a usable token in an
    // id. Normalising here is what lets the address book's fields use the same
    // association as every other form without each one deriving its own.
    $html = Blade::render(
        <<<'BLADE'
            <x-field label="City or town" name="form.city" :error="$message">
                <x-input id="form.city" :error="$failed" />
            </x-field>
        BLADE,
        ['message' => 'Required.', 'failed' => true],
    );

    // The label still targets the control by the id the control actually has,
    // which is what keeps the label association working.
    expect($html)->toContain('for="form.city"');
    expect($html)->toContain('id="form.city"');

    // And the message it points at is referable.
    expect($html)->toContain('id="form-city-error"');
    expect($html)->toContain('aria-describedby="form-city-error"');
});

it('gives a hint an id so it can be referenced', function (): void {
    $html = renderField(withHint: true);

    expect($html)->toContain('id="phone-hint"');
});

/*
 * Navigation.
 *
 * Where somebody is. Both the customer header and the admin rail indicated the
 * current page with a background tint and a weight change, which is invisible to
 * a screen reader and low contrast for anyone who can see but cannot rely on it.
 */
it('marks the current page on an active nav link', function (): void {
    $html = Blade::render('<x-nav-link :active="true" href="/orders">Orders</x-nav-link>');

    expect($html)->toContain('aria-current="page"');
});

it('does not mark an inactive nav link as the current page', function (): void {
    // Otherwise every link on the page claims to be the page you are on, which
    // is the same failure as marking none of them.
    $html = Blade::render('<x-nav-link :active="false" href="/orders">Orders</x-nav-link>');

    expect($html)->not->toContain('aria-current');
});

it('gives navigation links a visible focus indicator', function (): void {
    // The most repeated interactive element in the product had no focus style of
    // its own, so a keyboard user tabbing the header could not see where they
    // were between one link and the next.
    $html = Blade::render('<x-nav-link href="/orders">Orders</x-nav-link>');

    expect($html)->toContain('focus-visible:outline');
});

it('describes the account dropdown as a disclosure rather than a menu', function (): void {
    // role="menu" promises arrow-key navigation, a roving tabindex and managed
    // focus. This dropdown implemented none of it, and a partially implemented
    // widget pattern is worse than none: it tells assistive technology to expect
    // keys that do nothing. What it really is -- a button that opens a list of
    // links -- is exactly what aria-expanded plus aria-controls already says.
    $html = $this->actingAs(userWithRole('customer'))
        ->get('/dashboard')
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('aria-controls="account-menu"')
        ->toContain('aria-expanded')
        ->not->toContain('role="menu"')
        ->not->toContain('role="menuitem"')
        ->not->toContain('aria-haspopup');
});

it('lets a keyboard user dismiss the mobile navigation', function (): void {
    // A drawer that can only be closed by the button that opened it is a
    // keyboard trap on a phone-sized window, and Escape is the one key a
    // keyboard user can always reach.
    $html = test()->get('/products')->assertOk()->getContent();

    expect($html)
        ->toContain('x-on:keydown.escape.window="open = false"')
        ->toContain('x-on:click.outside');
});

/*
 * Invariants across real pages.
 *
 * These are the tests that would have caught the original gap, because they look
 * at rendered pages rather than at components. They are also the ones most
 * likely to fail on a page nobody edited by hand, which is the point: 32 files
 * use x-field and not all of them were written by the same person.
 */
it('points every label on a rendered page at a control that exists', function (string $path): void {
    // The label a field renders and the id its control renders come from two
    // different places in the template. When they drift apart the label stops
    // describing anything, which is invisible in a screenshot.
    $response = test()->get($path)->assertOk();
    $html = $response->getContent();

    preg_match_all('/<label[^>]*\sfor="([^"]+)"/i', $html, $labels);
    preg_match_all('/\sid="([^"]+)"/i', $html, $ids);

    $dangling = array_values(array_diff($labels[1], $ids[1]));

    expect($dangling)->toBe([], "{$path} has labels pointing at missing controls: ".implode(', ', $dangling));
})->with([
    '/',
    '/products',
    '/auctions',
    '/login',
    '/register',
    '/forgot-password',
]);

it('leaves no control on a rendered page pointing at a message that is absent', function (string $path): void {
    $html = test()->get($path)->assertOk()->getContent();

    preg_match_all('/aria-describedby="([^"]+)"/i', $html, $described);
    preg_match_all('/\sid="([^"]+)"/i', $html, $ids);

    $dangling = array_values(array_diff($described[1], $ids[1]));

    expect($dangling)->toBe([], "{$path} describes controls using ids that do not exist: ".implode(', ', $dangling));
})->with([
    '/',
    '/products',
    '/auctions',
    '/login',
    '/register',
    '/forgot-password',
]);

it('leaves no tab claiming a panel that does not exist', function (): void {
    // A tab with aria-controls pointing at nothing is a widget that promises a
    // relationship it does not have. The wallet page is the one place in the
    // product that used this pattern, and it declared role="tab" without ever
    // declaring a panel for it to reveal.
    //
    // A customer role rather than a bare factory user: /wallet is gated on
    // wallets.view, so a user with no role is refused the page and the assertion
    // would be checking the 403 instead of the tabs.
    $html = $this->actingAs(userWithRole('customer'))
        ->get('/wallet')
        ->assertOk()
        ->getContent();

    preg_match_all('/\saria-controls="([^"]+)"/i', $html, $controls);
    preg_match_all('/\sid="([^"]+)"/i', $html, $ids);

    // aria-controls is also used legitimately by the account disclosure, whose
    // target is only rendered when the menu is open. Those are the disclosure's
    // own ids, so they are allowed to be absent; a tab panel's is not.
    $tabPanels = array_values(array_filter(
        preg_match_all('/aria-controls="(wallet-panel-[^"]+)"/i', $html, $matches) ? $matches[1] : []
    ));

    $dangling = array_values(array_diff($tabPanels, $ids[1]));

    expect($tabPanels)->not->toBe([], 'the wallet page declares no tab panels at all');
    expect($dangling)->toBe([], 'these tabs point at panels that do not exist: '.implode(', ', $dangling));
});

it('hides the wallet panels it is not showing rather than removing them', function (): void {
    // This is the half of the tabs contract that the check above cannot see. A
    // panel that is simply absent leaves the other tabs' aria-controls dangling,
    // so all three panels stay in the document and the two unselected ones are
    // hidden. Removing them was what made the other two tabs point at nothing.
    //
    // Both halves are asserted because either alone is a plausible mistake: the
    // hidden panels have to be really hidden, and the visible one really visible.
    $customer = userWithRole('customer');

    $creditsFirst = Livewire::actingAs($customer)
        ->test(WalletOverview::class)
        ->assertSeeHtml('id="wallet-panel-credits"');

    expect($creditsFirst->html())
        ->toContain('wallet-panel-store_wallet')
        ->toContain('wallet-panel-lots');

    $lotsSelected = Livewire::actingAs($customer)
        ->test(WalletOverview::class, ['tab' => 'lots']);

    // The selected tab is the only one marked selected.
    expect(substr_count($lotsSelected->html(), 'aria-selected="true"'))->toBe(1)
        ->and($lotsSelected->html())->toContain('aria-selected="true"');
});
