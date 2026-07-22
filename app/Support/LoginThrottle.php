<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Failed sign-in throttling (spec §5.1, hardened).
 *
 * The original implementation keyed the limiter on `identifier|ip`, which two
 * different attackers defeat trivially:
 *
 *   - **Credential stuffing from a botnet.** One attempt per IP against the
 *     same account never trips a combined key, because each new IP starts a
 *     fresh counter. Guarding the ACCOUNT independently of origin fixes this.
 *
 *   - **Password spraying.** One attempt against each of a hundred accounts
 *     from a single host never trips an account key either. Guarding the IP
 *     independently of the account fixes that direction.
 *
 * So we keep two counters and trip on whichever fills first:
 *
 *   account  5 failures  → locked for 15 minutes  (the spec's stated rule)
 *   ip      20 failures  → locked for 15 minutes  (spray brake, invisible to
 *                                                  a legitimate typo)
 *
 * Both decay rather than latching permanently. A permanent lock would hand any
 * passer-by a denial-of-service against a named officer: five wrong guesses at
 * a known username and that person cannot work until an admin intervenes. A
 * timed lock costs an attacker everything and a real user fifteen minutes.
 */
final class LoginThrottle
{
    public const MAX_ACCOUNT_ATTEMPTS = 5;

    private const MAX_IP_ATTEMPTS = 20;

    private const DECAY_SECONDS = 900; // 15 minutes

    public function __construct(
        private readonly string $identifier,
        private readonly string $ip,
    ) {}

    public static function for(string $identifier, string $ip): self
    {
        return new self(Str::lower(trim($identifier)), $ip);
    }

    private function accountKey(): string
    {
        // Hashed so a raw email address never lands in the cache store's keys.
        return 'login:account:'.sha1($this->identifier);
    }

    private function ipKey(): string
    {
        return 'login:ip:'.sha1($this->ip);
    }

    /** True when either counter has been exhausted. */
    public function isLocked(): bool
    {
        return RateLimiter::tooManyAttempts($this->accountKey(), self::MAX_ACCOUNT_ATTEMPTS)
            || RateLimiter::tooManyAttempts($this->ipKey(), self::MAX_IP_ATTEMPTS);
    }

    /** Seconds until the account counter frees up, shown to the user. */
    public function secondsRemaining(): int
    {
        return max(
            RateLimiter::availableIn($this->accountKey()),
            RateLimiter::availableIn($this->ipKey()),
        );
    }

    /**
     * Record a failure and return how many account attempts have now been used,
     * so the caller can render the spec's "(N of 5)" copy.
     */
    public function recordFailure(): int
    {
        RateLimiter::hit($this->ipKey(), self::DECAY_SECONDS);
        RateLimiter::hit($this->accountKey(), self::DECAY_SECONDS);

        return RateLimiter::attempts($this->accountKey());
    }

    /** Wipe both counters after a genuine sign-in. */
    public function clear(): void
    {
        RateLimiter::clear($this->accountKey());
        RateLimiter::clear($this->ipKey());
    }

    /**
     * The message for a failed attempt, in the spec's wording (§5.1).
     *
     * Deliberately identical whether the account exists, is inactive, or the
     * password was simply wrong. Distinguishing them would turn this form into
     * a free account-enumeration oracle.
     */
    public function failureMessage(int $attempts): string
    {
        if ($attempts >= self::MAX_ACCOUNT_ATTEMPTS) {
            return 'Account temporarily locked after 5 failed attempts.';
        }

        return "Incorrect username or password ({$attempts} of ".self::MAX_ACCOUNT_ATTEMPTS.').';
    }

    public function lockedMessage(): string
    {
        $minutes = (int) ceil($this->secondsRemaining() / 60);

        return 'Account temporarily locked after 5 failed attempts.'
            .($minutes > 0 ? " Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.' : '');
    }
}
