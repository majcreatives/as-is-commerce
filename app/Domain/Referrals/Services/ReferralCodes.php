<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Services;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A customer's own referral code.
 *
 * RANDOM, NOT SEQUENTIAL. Eight characters from an unambiguous alphabet gives
 * roughly a trillion possibilities, so codes cannot be walked. A sequential
 * code would let anybody enumerate the customer base by counting, and a code
 * derived from a phone number or an email would put personal data in a URL
 * people paste into group chats.
 *
 * THE ALPHABET EXCLUDES CHARACTERS THAT LOOK ALIKE. No `0`/`O`, no `1`/`I`/`L`.
 * A referral code is read aloud, written on paper and typed by somebody who did
 * not choose it, and a code that is impossible to transcribe is a code nobody
 * uses.
 *
 * ISSUED ONCE, THEN IMMUTABLE. A code is generated the first time it is asked
 * for and never changes: a link somebody shared last month has to keep working,
 * and a rotating code would silently orphan every share.
 *
 * URL-SAFE AND CASE-INSENSITIVE ON THE WAY IN. Stored upper case, resolved
 * after normalising, so a link typed in lower case still finds its owner.
 */
class ReferralCodes
{
    /**
     * Unambiguous when read aloud or written down.
     *
     * 32 characters, 8 positions: about 1.1 trillion codes.
     */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LENGTH = 8;

    /**
     * This customer's code, generating one the first time it is needed.
     *
     * Generated lazily rather than at registration, so an account that never
     * refers anybody never occupies a code -- and so this feature could be
     * switched on for an existing customer base without a backfill.
     */
    public function forUser(User $user): string
    {
        if ($user->referral_code !== null) {
            return $user->referral_code;
        }

        // A handful of attempts, because a collision at this size is
        // vanishingly unlikely and the unique index is what actually decides.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $user->referral_code = $this->generate();
                $user->save();

                return $user->referral_code;
            } catch (UniqueConstraintViolationException) {
                // Somebody else took it between generating and saving, or this
                // customer got one on another request. Re-read and try again.
                $user->refresh();

                if ($user->referral_code !== null) {
                    return $user->referral_code;
                }
            }
        }

        throw new RuntimeException('Could not issue a unique referral code.');
    }

    /**
     * Find the customer a code belongs to.
     *
     * Returns null for anything unrecognised rather than throwing: an invalid
     * code in a URL is somebody mistyping a link, not an error worth
     * interrupting a registration for.
     */
    public function owner(?string $code): ?User
    {
        $normalized = $this->normalize($code);

        if ($normalized === null) {
            return null;
        }

        return User::where('referral_code', $normalized)->first();
    }

    /**
     * Tidy a code that arrived from a URL or a form.
     *
     * Upper-cased and stripped of anything outside the alphabet, so a code
     * pasted with a stray space or a trailing full stop still resolves. Null
     * for anything that could not be a code at all, which keeps a malformed
     * query string away from the database.
     */
    public function normalize(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $cleaned = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($code))) ?? '';

        return mb_strlen($cleaned) === self::LENGTH ? $cleaned : null;
    }

    private function generate(): string
    {
        $alphabet = self::ALPHABET;
        $max = mb_strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            // Cryptographically random rather than mt_rand: a guessable code
            // is a code somebody can attribute themselves to.
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * The link a customer shares.
     */
    public function shareUrl(string $code): string
    {
        return route('register', ['ref' => $code]);
    }

    /**
     * @return array<string, string>
     */
    public function shareText(string $code): array
    {
        return [
            'url' => $this->shareUrl($code),
            'code' => $code,
        ];
    }

    /**
     * A code that could never be issued, for tests and fixtures.
     */
    public static function length(): int
    {
        return self::LENGTH;
    }

    /**
     * Deterministic code generation, for seeding fixtures only.
     */
    public function fromSeed(string $seed): string
    {
        $hash = strtoupper(substr(hash('sha256', $seed), 0, 32));
        $alphabet = self::ALPHABET;
        $code = '';

        foreach (str_split(substr($hash, 0, self::LENGTH * 2), 2) as $pair) {
            $code .= $alphabet[hexdec($pair) % mb_strlen($alphabet)];
        }

        return Str::substr($code, 0, self::LENGTH);
    }
}
