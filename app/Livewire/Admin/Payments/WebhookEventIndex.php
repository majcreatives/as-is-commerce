<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Payments;

use App\Enums\WebhookProcessingStatus;
use App\Models\PaymentWebhookEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Webhook deliveries, for diagnosing a payment that did not land.
 *
 * Payloads are shown redacted: card and authorization details are echoed by
 * the provider, are not needed to explain a payment, and an admin screen is
 * the wrong place for them to surface.
 */
#[Layout('components.layouts.app')]
#[Title('Payment events')]
class WebhookEventIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    public ?int $expandedId = null;

    public function mount(): void
    {
        $this->authorize('payment_events.view');
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function toggle(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    /**
     * @return LengthAwarePaginator<int, PaymentWebhookEvent>
     */
    public function events(): LengthAwarePaginator
    {
        return PaymentWebhookEvent::query()
            ->with('purchase')
            ->when($this->status !== '', fn ($q) => $q->where('processing_status', $this->status))
            ->orderByDesc('id')
            ->paginate(20);
    }

    public function render(): View
    {
        return view('livewire.admin.payments.webhook-event-index', [
            'events' => $this->events(),
            'statuses' => WebhookProcessingStatus::cases(),
        ]);
    }
}
