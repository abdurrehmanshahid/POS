<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (RFC 6238), the second factor, implemented entirely offline.
 *
 * Cost note: this is free and always will be. There is no SMS, no vendor, no
 * API call and no per-user fee. The phone app and the server independently
 * derive the same six digits from a shared secret and the wall clock, so the
 * only infrastructure required is that both agree roughly what time it is.
 *
 * Two properties of TOTP drive the whole design here:
 *
 *  1. A code is valid for a WINDOW, not an instant. We accept one timestep of
 *     drift either side (±30s) so a slightly-off phone clock still works. The
 *     cost is that the same six digits stay valid for up to 90 seconds, which
 *     is why {@see verify()} refuses to accept a timestep at or below the last
 *     one the account already spent. Without that, one observed code could
 *     authorise several destructive actions in a row.
 *
 *  2. The secret is the entire security boundary. It is encrypted at rest, it
 *     is shown to the user exactly once during enrolment, and it never appears
 *     in a log, a URL or an audit row.
 */
final class TwoFactor
{
    /**
     * Accept one 30-second step of clock drift on either side. Google
     * Authenticator itself is generous here; 1 is the usual production value:
     * large enough for a phone that has not synced today, small enough that a
     * shoulder-surfed code expires almost immediately.
     */
    private const WINDOW = 1;

    private const RECOVERY_CODE_COUNT = 8;

    /** Memoised per request; this is consulted on every sign-in. */
    private static ?bool $enabled = null;

    public function __construct(private readonly Google2FA $engine) {}

    /**
     * The institute-wide switch for the whole second factor.
     *
     * Everything that can DEMAND a code asks this first: role obligation
     * ({@see User::requiresTwoFactor()}), the super admin's
     * standing obligation, the sign-in challenge, and the step-up prompt. One
     * switch rather than a condition per call site, because the failure mode of
     * scattering them is a screen that still demands a code nobody can produce.
     *
     * Turning it off does NOT erase anything: enrolled secrets and recovery
     * codes stay encrypted at rest, so ticking `twofa_required` back on in
     * Settings restores the factor for everyone who had it, with no re-enrolment.
     *
     * It defaults ON — the column default and both seeders say true — so an
     * install that never touches Settings is protected. It is off in this
     * database because the institute asked for it to be.
     */
    public static function enabled(): bool
    {
        return self::$enabled ??= (bool) Setting::current()->twofa_required;
    }

    /** Tests flip the switch; without this they would share one memoised value. */
    public static function forgetEnabled(): void
    {
        self::$enabled = null;
    }

    /** A fresh base32 secret to hand to the authenticator app. */
    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    /**
     * The `otpauth://` URI the QR code encodes. Contains the secret, so it is
     * rendered straight to the enrolment screen and never persisted or logged.
     */
    public function provisioningUri(string $account, string $secret): string
    {
        return $this->engine->getQRCodeUrl(
            config('institute.totp_issuer', config('app.name')),
            $account,
            $secret,
        );
    }

    /**
     * The provisioning URI as an inline SVG QR code, base64'd into a data URI.
     *
     * Rendered locally by bacon/bacon-qr-code, deliberately NOT via an image
     * API such as the old Google Charts endpoint, because handing a third party
     * a URL that contains the TOTP secret would defeat the entire exercise.
     * SVG output also means no imagick/GD extension is needed, which matters on
     * shared cPanel hosting where you cannot install PHP extensions.
     */
    public function qrCodeDataUri(string $account, string $secret): string
    {
        $svg = (new Writer(new ImageRenderer(
            new RendererStyle(220, 1),
            new SvgImageBackEnd,
        )))->writeString($this->provisioningUri($account, $secret));

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Verify a six-digit code against an identity's stored secret, consuming
     * the timestep so it cannot be replayed.
     *
     * `verifyKeyNewer()` returns the matched timestep (an int) when the code is
     * valid AND strictly newer than the one supplied, or false otherwise. We
     * persist that timestep, which is what makes each code single-use.
     *
     * @param  Model&object{two_factor_secret: ?string, two_factor_last_timestep: ?int}  $identity
     */
    public function verify(Model $identity, string $code): bool
    {
        $secret = $identity->two_factor_secret;
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (! $secret || strlen($code) !== 6) {
            return false;
        }

        // `?? 0` is load-bearing, not defensive padding. When google2fa is given
        // a NULL "old timestamp" it reports success as boolean `true` rather
        // than as the matched timestep, so storing the return value verbatim
        // would write 1 into the column and compare every later code against
        // timestep 1 (i.e. 1970), silently disabling replay protection after the
        // very first use. Passing 0 keeps the parameter non-null, which makes
        // the library return the real integer timestep every time.
        $timestep = $this->engine->verifyKeyNewer(
            $secret,
            $code,
            $identity->two_factor_last_timestep ?? 0,
            self::WINDOW,
        );

        if ($timestep === false || $timestep === true) {
            return $timestep === true; // defensive: never persist a boolean
        }

        // Burn the timestep. Anything at or before this is now refused, so the
        // same code cannot authorise a second delete inside its live window.
        $identity->forceFill(['two_factor_last_timestep' => $timestep])->save();

        return true;
    }

    /**
     * Verify against a secret that is not yet stored on the model, used during
     * enrolment, where the user must prove the app works before we commit the
     * secret and lock them into needing it.
     */
    public function verifyPendingSecret(string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        return strlen($code) === 6
            && $this->engine->verifyKey($secret, $code, self::WINDOW) !== false;
    }

    /**
     * Generate recovery codes. Returns the PLAINTEXT codes for one-time display
     * alongside the hashes to store, the caller shows the former and persists
     * the latter. We hash rather than encrypt so that even we cannot read them
     * back; the trade-off is that "show me my codes again" is impossible by
     * design, and losing them means regenerating.
     *
     * @return array{plain: list<string>, hashed: list<string>}
     */
    public function generateRecoveryCodes(): array
    {
        $plain = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            // 10 unambiguous chars, dashed for legibility when written down.
            $plain[] = strtoupper(Str::random(5).'-'.Str::random(5));
        }

        return [
            'plain' => $plain,
            'hashed' => array_map(fn (string $c) => Hash::make($c), $plain),
        ];
    }
}
