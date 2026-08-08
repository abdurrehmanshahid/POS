<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Institute "today"
    |--------------------------------------------------------------------------
    |
    | Overdue challans and this-month counts are measured against this date.
    | Production leaves it null to use the real current date (spec §17.5). Pin
    | it (e.g. 2026-07-15, the prototype's fixed "today") to reproduce the
    | prototype's exact dashboard figures in the demo.
    |
    */
    'today' => env('INSTITUTE_TODAY'),

    /*
    |--------------------------------------------------------------------------
    | Identifier prefix
    |--------------------------------------------------------------------------
    |
    | Stamped onto every system-generated identifier: student codes, admission
    | numbers and challan numbers alike, so anything printed on a document is
    | recognisably this institute's.
    |
    | Single-sourced here rather than written into Sequences, because changing
    | it is not a code change and because the seeder, the wizard previews and
    | the backfill migration all have to agree with it exactly.
    |
    | Changing this AFTER go-live does not rewrite existing records: identifiers
    | are already printed on issued challans. New records would simply carry the
    | new prefix, leaving the series inconsistent. Decide it once, up front.
    |
    */
    'code_prefix' => env('INSTITUTE_CODE_PREFIX', 'BBT-'),

    /*
    |--------------------------------------------------------------------------
    | Self-service password reset
    |--------------------------------------------------------------------------
    |
    | OFF by default, and deliberately so. With no mail server configured there
    | is no way to prove someone owns an address, and a reset flow that cannot
    | prove ownership is just an account-takeover form (see spec §17.2 and the
    | note in resources/views/livewire/pages/auth/forgot.blade.php).
    |
    | While this is false, passwords are reset by an administrator issuing a
    | one-time temporary password. Turn it on only once MAIL_* actually sends, | Gmail SMTP with an app password, Brevo or Resend all work on free tiers.
    |
    */
    'self_service_reset' => env('INSTITUTE_SELF_SERVICE_RESET', false),

    /*
    |--------------------------------------------------------------------------
    | Two-factor authentication
    |--------------------------------------------------------------------------
    |
    | The issuer label shown beside the account in Google Authenticator, Authy,
    | 1Password and friends. Keep it recognisable, it is what the user reads
    | when deciding which of a dozen six-digit codes to type.
    |
    */
    'totp_issuer' => env('INSTITUTE_TOTP_ISSUER', 'Big Binary Tech'),

    /*
    | Payment methods offered in the mark-paid dialog (spec §7.6).
    */
    'payment_methods' => ['Cash', 'Bank transfer', 'Card', 'Wallet', 'Cheque'],

    /*
    |--------------------------------------------------------------------------
    | Contact details printed on the fee voucher
    |--------------------------------------------------------------------------
    |
    | The fee challan is the one document that leaves the building and reaches
    | a parent, and its footer is where somebody goes when a payment does not
    | show up. It previously carried `+92 42 000 0000`, a placeholder that looks
    | like a real Lahore landline, so a parent chasing a missing fee would have
    | dialled a dead number and concluded the institute was not contactable.
    |
    | The phone deliberately has NO default. A blank one is omitted from the
    | voucher entirely, which is honest; a fabricated one is not.
    |
    */
    'contact_email' => env('INSTITUTE_CONTACT_EMAIL', 'accounts@bbt.edu.pk'),
    'contact_phone' => env('INSTITUTE_CONTACT_PHONE'),

    /*
    |--------------------------------------------------------------------------
    | Removed: revenue_history
    |--------------------------------------------------------------------------
    |
    | This key held six hardcoded monthly figures that the dashboard rendered as
    | Jan to Jun on its revenue chart, with only the current month computed from
    | real data. They were invented, and they sat directly beside the reconciled
    | billed / received / outstanding totals, which lent them credibility they
    | had not earned.
    |
    | Ledger::revenueTrend() now derives every month from payments.received_at.
    | The chart is shorter until real history accumulates, which is the correct
    | thing for it to be.
    |
    */
];
