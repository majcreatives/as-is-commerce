<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IdempotencyStatus;
use Database\Factories\IdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A claim on a named operation, so it happens at most once.
 *
 * @property int $id
 * @property string $operation
 * @property string $idempotency_key
 * @property int|null $user_id
 * @property IdempotencyStatus $status
 * @property array<string, mixed>|null $result
 * @property string|null $failure_reason
 * @property Carbon|null $completed_at
 */
class IdempotencyKey extends Model
{
    /** @use HasFactory<IdempotencyKeyFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IdempotencyStatus::class,
            'result' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->status === IdempotencyStatus::Completed;
    }

    public function isPending(): bool
    {
        return $this->status === IdempotencyStatus::Pending;
    }

    public function isFailed(): bool
    {
        return $this->status === IdempotencyStatus::Failed;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
