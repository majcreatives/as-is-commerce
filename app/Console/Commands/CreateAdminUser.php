<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\Phone\PhoneNumberNormalizer;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creates an administrator account from supplied credentials.
 *
 * Deliberately a command rather than a seeder: an admin account must never be
 * created as a side effect of `db:seed`, and its password must never exist in
 * source control. Credentials come from the environment or an interactive
 * hidden prompt, never from a default baked into this file.
 */
class CreateAdminUser extends Command
{
    protected $signature = 'app:create-admin
                            {--role=super_admin : Role to assign (admin or super_admin)}';

    protected $description = 'Create an administrator account from ADMIN_PHONE/ADMIN_PASSWORD or interactive input';

    public function handle(PhoneNumberNormalizer $normalizer): int
    {
        $role = (string) $this->option('role');

        if (! in_array($role, ['admin', 'super_admin'], true)) {
            $this->error('Role must be either "admin" or "super_admin".');

            return self::FAILURE;
        }

        $phoneInput = (string) config('admin.phone', '');
        if ($phoneInput === '') {
            $phoneInput = (string) $this->ask('Admin phone number');
        }

        $phone = $normalizer->normalize($phoneInput);
        if ($phone === null) {
            $this->error('That is not a valid Ghanaian mobile number.');

            return self::FAILURE;
        }

        if (User::where('phone', $phone)->exists()) {
            $this->error('An account already exists for that phone number.');

            return self::FAILURE;
        }

        $password = (string) config('admin.password', '');
        if ($password === '') {
            $password = (string) $this->secret('Admin password (input hidden)');
        }

        if (strlen($password) < 12) {
            $this->error('Refusing to create an admin with a password shorter than 12 characters.');

            return self::FAILURE;
        }

        $name = (string) config('admin.name', '');
        $email = (string) config('admin.email', '');

        DB::transaction(function () use ($phone, $password, $name, $email, $role): void {
            $user = new User([
                'name' => $name !== '' ? $name : null,
                'phone' => $phone,
                'email' => $email !== '' ? $email : null,
                'password' => $password,
            ]);

            $user->status = UserStatus::Active;
            $user->save();

            $user->assignRole($role);
        });

        $this->info("Administrator created with role [{$role}] for {$normalizer->forDisplay($phone)}.");
        $this->line('Store this password in your password manager. It is not recoverable from the database.');

        return self::SUCCESS;
    }
}
