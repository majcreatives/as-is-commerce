<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string|null $name
 * @property string $phone Canonical E.164, e.g. +233244123456
 * @property Carbon|null $phone_verified_at
 * @property string|null $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property UserStatus $status
 */
#[Fillable(['name', 'phone', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /**
     * Whether this account is currently permitted to authenticate.
     *
     * Checked server-side on every login attempt; never inferred from a
     * client-supplied value.
     */
    public function canAuthenticate(): bool
    {
        return $this->status->canAuthenticate();
    }
}
