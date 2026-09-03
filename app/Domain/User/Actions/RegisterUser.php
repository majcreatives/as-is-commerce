<?php

declare(strict_types=1);

namespace App\Domain\User\Actions;

use App\Domain\Shared\Phone\PhoneNumberNormalizer;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates a customer account.
 *
 * Lives outside the controller/Livewire layer so registration has one
 * definition regardless of which entry point calls it.
 */
final class RegisterUser
{
    public function __construct(
        private readonly PhoneNumberNormalizer $normalizer,
    ) {}

    /**
     * @param  array{name?: string|null, phone: string, email?: string|null, password: string}  $data
     */
    public function handle(array $data): User
    {
        // Normalize before persisting so the unique index sees one canonical
        // form -- 0244123456 and +233244123456 must not become two accounts.
        $phone = $this->normalizer->normalize($data['phone']);

        if ($phone === null) {
            throw new InvalidArgumentException('Phone number must be validated before registration.');
        }

        return DB::transaction(function () use ($data, $phone): User {
            $user = new User([
                'name' => $data['name'] ?? null,
                'phone' => $phone,
                'email' => $data['email'] ?? null,
                'password' => $data['password'],
            ]);

            // Set outside the fillable set: account status is a moderation
            // concern and must never be mass-assignable from request input.
            $user->status = UserStatus::Active;
            $user->save();

            $user->assignRole('customer');

            return $user;
        });
    }
}
