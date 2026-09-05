<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * One thing the platform told somebody.
 *
 * Extends Laravel's own `DatabaseNotification` rather than replacing it, so
 * the framework's `Notifiable` trait, its relations and its `markAsRead()` all
 * work unchanged. What this adds is accessors over the JSON payload, so a
 * template reads `$notification->title` instead of digging into an array, and
 * a typed `eventType`.
 *
 * INFORMATIONAL, NEVER AUTHORITATIVE. Nothing in the application reads a
 * notification to decide anything. Deleting every row here would leave the
 * ledgers, the auctions, the orders and the inventory exactly as they are --
 * which is the point, and why a notification failure can never fail a
 * transaction.
 *
 * @property string $id
 * @property string $event_type
 * @property string|null $event_key
 * @property array<string, mixed> $data
 * @property Carbon|null $read_at
 * @property string|null $mail_status
 * @property string|null $mail_failure_reason
 * @property Carbon|null $mail_sent_at
 */
class Notification extends DatabaseNotification
{
    protected $table = 'notifications';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'mail_sent_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------ Payload

    /**
     * What this notification is about, as an enum.
     *
     * Falls back to null rather than throwing on an identifier this version
     * of the application no longer knows: a notification written months ago
     * should still render, not break somebody's list.
     */
    public function eventType(): ?NotificationType
    {
        return NotificationType::tryFrom($this->event_type);
    }

    public function getTitleAttribute(): string
    {
        return (string) ($this->data['title'] ?? $this->eventType()?->label() ?? 'Notification');
    }

    public function getMessageAttribute(): string
    {
        return (string) ($this->data['message'] ?? '');
    }

    /**
     * Where this notification points, if anywhere.
     *
     * A path within the application, never an absolute URL from elsewhere.
     * The page it points at does its own authorization -- a link is not a
     * capability, and following one to somebody else's order still 404s.
     */
    public function getActionUrlAttribute(): ?string
    {
        $url = $this->data['action_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function getActionLabelAttribute(): ?string
    {
        $label = $this->data['action_label'] ?? null;

        return is_string($label) && $label !== '' ? $label : null;
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    public function mailFailed(): bool
    {
        return $this->mail_status === 'failed';
    }

    // -------------------------------------------------------------- Scopes

    /*
     * There is deliberately no `scopeUnread` here. The framework's
     * DatabaseNotification already defines one, and re-declaring it with a
     * narrower generic only fights the parent's signature without changing
     * what the query does.
     */

    /**
     * Notifications whose email did not get through.
     *
     * The administrative work queue. A failed email is not a failed business
     * event -- the in-app notification is there and the transaction stands --
     * but somebody may still want to know.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMailFailed(Builder $query): Builder
    {
        return $query->where('mail_status', 'failed');
    }
}
