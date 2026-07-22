<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Super admin TOTP enrolment.
 *
 * Thin wrapper that renders the shared enrolment component against the
 * `superadmin` guard, so the QR, recovery codes and replay guard are literally
 * the same code path the staff portal uses rather than a second implementation
 * that could drift.
 */
new #[Layout('components.layouts.guest')] class extends Component {
    //
}; ?>

<div>
    <livewire:pages.auth.two-factor-setup guard="superadmin" />
</div>
