<?php

declare(strict_types=1);

use App\Livewire\Delivery\AddressBookPage;
use App\Models\Address;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/*
 * Telling somebody that the product is working.
 *
 * THE GAP THESE TESTS WERE WRITTEN FOR.
 *
 * Twenty-four of the fifty-three Livewire views in the product could send a
 * request and then show absolutely nothing while it was in flight. No spinner,
 * no dimmed row, no disabled button. Eight of them went further and offered a
 * control that mutates something -- saving an address, sending an order to a
 * delivery address, saving notification preferences, creating a draft auction --
 * and pressing it twice was possible, because nothing about the control changed
 * between the first press and the server's answer.
 *
 * The server is not at risk from that: every one of those actions goes through a
 * domain action with its own idempotency and locking, so a double press cannot
 * create two addresses or two auctions. This is a feedback problem, not a
 * correctness one, and it is worth being precise about which is which -- a
 * customer who presses Save twice and sees the page blink is annoyed, not
 * charged twice.
 *
 * WHY A FILE SCAN RATHER THAN ONE TEST PER PAGE.
 *
 * The fix is the same in all eight places -- the product's existing
 * wire:loading.attr="disabled" convention -- so asserting it eight times would
 * test eight copies of one rule and still miss the ninth file somebody adds
 * next month. The invariant worth protecting is per view, and it is checkable
 * without a browser, which is the only kind of check this suite can make.
 */

/**
 * Every Livewire view, as [relative path, lines].
 *
 * @return array<int, array{0: string, 1: array<int, string>}>
 */
function livewireViews(): array
{
    return array_map(
        static fn ($file): array => [$file->getRelativePathname(), file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: []],
        File::allFiles(resource_path('views/livewire')),
    );
}

/**
 * True when the element opened on this line goes on to trigger a Livewire
 * request. Attributes wrap, so a button's action is not always on the line the
 * tag opens on.
 */
function triggersRequest(string $line, array $lines, int $index): bool
{
    $window = implode("\n", array_slice($lines, $index, 5));

    return preg_match('/wire:(click|submit)/', $window) === 1;
}

it('gives every view that makes a request some way of saying so', function (): void {
    $silent = [];

    foreach (livewireViews() as [$path, $lines]) {
        // A view with no loading affordance anywhere is the whole problem. One
        // that has any at all is taken to be handled, which is why the button
        // level is checked separately below.
        if (preg_match('/wire:(loading|stream|target)/', implode("\n", $lines)) === 1) {
            continue;
        }

        foreach ($lines as $index => $line) {
            if (str_contains($line, '<button') && triggersRequest($line, $lines, $index)) {
                $silent[] = $path.':'.($index + 1).' button';
            }

            if (str_contains($line, '<form') && triggersRequest($line, $lines, $index)) {
                $silent[] = $path.':'.($index + 1).' form';
            }
        }
    }

    expect($silent)->toBe([], "these controls make a request with no loading feedback:\n".implode("\n", $silent));
});

it('locks a control that changes something while the change is in flight', function (): void {
    // The specific requirement, separated from the one above because a view can
    // satisfy "has some loading affordance" with a spinner on a table while
    // leaving the button that saves enabled. These are the controls that write.
    $unlocked = [];

    foreach (livewireViews() as [$path, $lines]) {
        $source = implode("\n", $lines);

        // Each of these names a Livewire method that mutates state.
        if (preg_match('/wire:(click|submit)="(save|delete|create|update|remove|makeDefault|confirmRefund|requestRefund)/', $source) !== 1) {
            continue;
        }

        if (str_contains($source, 'wire:loading.attr="disabled"')) {
            continue;
        }

        $unlocked[] = $path;
    }

    expect($unlocked)->toBe([], "these views mutate state with no disabled-while-loading control:\n".implode("\n", $unlocked));
});

it('stops a customer saving an address twice', function (): void {
    // The concrete case, asserted on the page with its form actually open,
    // because the save button does not exist until the customer asks for it --
    // asserting on the initial render would pass while testing nothing.
    //
    // The customer role is what carries addresses.manage; a bare factory user is
    // refused the page, which is the authorization working rather than a fault.
    $customer = userWithRole('customer');

    $html = Livewire::actingAs($customer)
        ->test(AddressBookPage::class)
        ->call('startAdding')
        ->assertOk()
        ->html();

    expect($html)->toContain('Save address');

    // The button that writes, and the one that deletes.
    expect(substr_count($html, 'wire:loading.attr="disabled"'))->toBeGreaterThanOrEqual(2);
});

it('locks the button that assigns a delivery address to an order', function (): void {
    // This one writes to an order a rider is about to act on, so a second press
    // landing mid-flight is the version of this bug that has consequences.
    //
    // Two details of the setup are load-bearing, and both are the kind of thing
    // that makes this test pass while proving nothing. The order comes first,
    // because checkout adopts an address that already exists and would leave
    // nothing for this page to do; and the address is added second, because with
    // an empty address book the page offers only "add an address" and renders no
    // submitting control at all.
    $customer = userWithRole('customer');
    $order = paidOrderFor($customer);

    expect($order->delivery->isAwaitingAddress())->toBeTrue();

    Address::factory()->ownedBy($customer)->create();

    $html = $this->actingAs($customer)
        ->get(route('orders.tracking', $order))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Send it here')
        ->toContain('wire:loading.attr="disabled"');
});

it('says which fulfilment filter is selected', function (): void {
    // The queue filters were a row of identical stat cards whose selected state
    // was only a border colour. aria-pressed is the attribute for a toggle that
    // is on or off, as distinct from aria-current, which would be claiming these
    // navigate somewhere.
    $html = $this->actingAs(userWithRole('admin'))
        ->get(route('admin.fulfilment'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('aria-pressed="true"');
});
