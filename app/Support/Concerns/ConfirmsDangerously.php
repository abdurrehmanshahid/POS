<?php

namespace App\Support\Concerns;

use App\Services\StepUp;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Shared state and gating for destructive actions.
 *
 * Every irreversible act in the panel goes through the same two independent
 * checks, and they guard against different failure modes:
 *
 *   1. **Type the record's own identifier.** Defends against the wrong *target*
 *, the classic "I meant to delete the other one" mistake. Copying the
 *      exact code forces you to read the row you are about to destroy. Note it
 *      is the record's identifier, never a generic word like DELETE, because a
 *      constant phrase becomes muscle memory within a week.
 *
 *   2. **A fresh authenticator code.** Defends against the wrong *person*, an
 *      unattended, unlocked machine. Because TwoFactor::verify() burns the
 *      timestep it accepts, one code cannot authorise two deletions.
 *
 * Neither check subsumes the other, which is why both are required.
 * Non-enrolled actors fall back to re-entering their password (see StepUp).
 */
trait ConfirmsDangerously
{
    public bool $dangerOpen = false;

    /** Discriminator the host component switches on, e.g. 'purge-student'. */
    public string $dangerKind = '';

    public ?int $dangerId = null;

    public string $dangerTitle = '';

    public string $dangerBody = '';

    /** The exact string the operator must retype. Empty = no phrase required. */
    public string $dangerPhrase = '';

    public string $dangerTyped = '';

    /** TOTP code, or password for actors without a second factor. */
    public string $dangerSecret = '';

    public string $dangerError = '';

    public bool $dangerIrreversible = false;

    public string $dangerConfirmLabel = 'Confirm';

    /** Optional free-text reason recorded on the audit row. */
    public string $dangerReason = '';

    public bool $dangerNeedsReason = false;

    /**
     * Open the confirmation dialog.
     *
     * @param  array{
     *   kind:string, id?:int|null, title:string, body:string,
     *   phrase?:string, irreversible?:bool, confirmLabel?:string, needsReason?:bool
     * }  $opts
     */
    protected function askDanger(array $opts): void
    {
        $this->dangerKind = $opts['kind'];
        $this->dangerId = $opts['id'] ?? null;
        $this->dangerTitle = $opts['title'];
        $this->dangerBody = $opts['body'];
        $this->dangerPhrase = $opts['phrase'] ?? '';
        $this->dangerIrreversible = $opts['irreversible'] ?? false;
        $this->dangerConfirmLabel = $opts['confirmLabel'] ?? 'Confirm';
        $this->dangerNeedsReason = $opts['needsReason'] ?? false;

        $this->dangerTyped = '';
        $this->dangerSecret = '';
        $this->dangerReason = '';
        $this->dangerError = '';
        $this->dangerOpen = true;
    }

    public function closeDanger(): void
    {
        $this->dangerOpen = false;
        $this->reset('dangerKind', 'dangerId', 'dangerTyped', 'dangerSecret', 'dangerReason', 'dangerError');
    }

    /**
     * Run both checks. Returns true only when the action may proceed; on
     * failure it sets `$dangerError` and leaves the dialog open.
     */
    protected function dangerCleared(Model $actor): bool
    {
        $this->dangerError = '';

        if ($this->dangerPhrase !== '' && trim($this->dangerTyped) !== $this->dangerPhrase) {
            $this->dangerError = 'Type '.$this->dangerPhrase.' exactly to confirm.';

            return false;
        }

        if ($this->dangerNeedsReason && trim($this->dangerReason) === '') {
            $this->dangerError = 'A reason is required.';

            return false;
        }

        try {
            app(StepUp::class)->confirm($actor, $this->dangerSecret);
        } catch (RuntimeException $e) {
            $this->dangerError = $e->getMessage();

            return false;
        }

        return true;
    }

    /** Label for the step-up input, so the dialog asks for the right thing. */
    public function dangerChallengeLabel(): string
    {
        $actor = auth()->guard('superadmin')->user() ?? auth()->user();

        return $actor ? app(StepUp::class)->challengeLabel($actor) : 'Confirm';
    }

    public function dangerUsesTotp(): bool
    {
        $actor = auth()->guard('superadmin')->user() ?? auth()->user();

        return $actor ? app(StepUp::class)->usesTotp($actor) : false;
    }
}
