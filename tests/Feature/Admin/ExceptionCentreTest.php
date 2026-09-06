<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Operations\Queries\ExceptionCentre;
use App\Domain\Operations\ValueObjects\OperationalException;
use App\Domain\Operations\ValueObjects\Severity;
use App\Domain\Refunds\Actions\ProcessRefund;
use App\Livewire\Admin\Operations\ExceptionCentrePage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * The exception centre.
 *
 * It detects and it reports. These tests hold it to that: an exception appears
 * because a real situation is true, it disappears when the situation stops
 * being true, and there is no way to make one go away without resolving it.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();

    $this->admin = userWithRole('admin');
});

/**
 * @param  list<OperationalException>  $exceptions
 */
function typesIn(array $exceptions): array
{
    return array_map(fn (OperationalException $e): string => $e->type, $exceptions);
}

// --------------------------------------------------------------- Access

it('is reachable by an administrator', function (): void {
    $this->actingAs($this->admin)->get(route('admin.exceptions'))->assertOk();
});

it('is refused to a customer', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.exceptions'))
        ->assertForbidden();
});

it('is refused to staff without the permission', function (): void {
    Livewire::actingAs(staffWith(['orders.view']))
        ->test(ExceptionCentrePage::class)
        ->assertForbidden();
});

// --------------------------------------------------------------- Detection

it('shows nothing on a platform with nothing wrong', function (): void {
    expect(app(ExceptionCentre::class)->all())->toBe([]);

    Livewire::actingAs($this->admin)
        ->test(ExceptionCentrePage::class)
        ->assertOk()
        ->assertSee('Nothing needs attention');
});

it('reports a paid order that could not be delivered against', function (): void {
    $order = blockedPaidOrder();

    $exceptions = app(ExceptionCentre::class)->all('payments');

    expect(typesIn($exceptions))->toContain('fulfilment_blocked')
        ->and($exceptions[0]->severity)->toBe(Severity::Critical)
        ->and($exceptions[0]->reference)->toBe($order->order_number);
});

it('stops reporting a blocked order once a refund is under way', function (): void {
    $order = blockedPaidOrder();

    expect(app(ExceptionCentre::class)->all('payments'))->toHaveCount(1);

    requestRefund($order, actor: $this->admin);

    // Not dismissed -- the situation genuinely changed. Somebody decided.
    expect(app(ExceptionCentre::class)->all('payments'))->toBe([]);
});

it('reports a refund the provider refused', function (): void {
    $refund = requestRefund(blockedPaidOrder(), actor: $this->admin);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'failed', 'RF-REFUSED'));
    app(ProcessRefund::class)->handle($refund->fresh(), $this->admin);

    expect(typesIn(app(ExceptionCentre::class)->all('refunds')))->toContain('refund_failed');
});

it('raises nothing against healthy stock, and the database refuses the state it looks for', function (): void {
    $product = stockedProduct(stock: 2);
    app(InventoryService::class)->reserve($product->fresh(), 1, reason: 'Stage 14 test');

    expect(app(ExceptionCentre::class)->all('inventory'))->toBe([]);

    // The impossible_stock detector is an invariant check rather than a code
    // path anything can reach: reserving beyond what is on hand is refused by
    // the database itself, not merely by the service. The detector stays
    // because an invariant nobody verifies is a hope -- it would catch a state
    // arriving from outside the application, a restore or a dropped
    // constraint -- but nothing the platform does can produce one.
    expect(fn () => $product->fresh()->forceFill(['stock_reserved' => 5])->saveQuietly())
        ->toThrow(QueryException::class);

    expect(app(ExceptionCentre::class)->all('inventory'))->toBe([]);
});

it('counts each category and totals them', function (): void {
    blockedPaidOrder();

    $counts = app(ExceptionCentre::class)->counts();

    expect($counts['payments'])->toBe(1)
        ->and($counts['total'])->toBe(array_sum(array_diff_key($counts, ['total' => null])));
});

it('orders the most serious first', function (): void {
    blockedPaidOrder();

    $exceptions = app(ExceptionCentre::class)->all();
    $ranks = array_map(fn (OperationalException $e): int => $e->severity->rank(), $exceptions);
    $sorted = $ranks;
    sort($sorted);

    expect($ranks)->toBe($sorted);
});

// --------------------------------------------------------------- Providers

it('asks no payment provider anything when it loads', function (): void {
    requestRefund(blockedPaidOrder(), actor: $this->admin);

    fakeHttp(['*' => Http::response(['status' => true, 'data' => []])]);

    Livewire::actingAs($this->admin)->test(ExceptionCentrePage::class)->assertOk();

    // The page is most needed on the worst day, which is exactly the day fifty
    // synchronous API calls would make it unusable.
    Http::assertNothingSent();
});

// --------------------------------------------------------------- Safety

it('offers no way to dismiss, acknowledge or repair anything', function (): void {
    foreach (['dismiss', 'acknowledge', 'resolve', 'repair', 'fix', 'markHandled'] as $method) {
        expect(method_exists(ExceptionCentrePage::class, $method))
            ->toBeFalse("ExceptionCentrePage must not expose {$method}()");

        expect(method_exists(ExceptionCentre::class, $method))
            ->toBeFalse("ExceptionCentre must not expose {$method}()");
    }
});

it('carries nothing sensitive in what it reports', function (): void {
    $order = blockedPaidOrder();

    $rendered = Livewire::actingAs($this->admin)
        ->test(ExceptionCentrePage::class)
        ->assertSee($order->order_number)
        ->html();

    foreach ([config('paystack.secret_key'), 'password', 'remember_token'] as $secret) {
        expect($rendered)->not->toContain((string) $secret);
    }
});
