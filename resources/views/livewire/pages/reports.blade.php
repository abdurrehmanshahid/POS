<?php

use App\Services\Reporting;
use App\Support\Period;
use Livewire\Volt\Component;

/**
 * Reports (spec §9.8, rebuilt).
 *
 * What changed and why:
 *  - The period selector used to be decorative: three options that filtered
 *    nothing. It now resolves to a real date range that every figure below is
 *    filtered by, and the resolved range is printed so the filter is never
 *    ambiguous.
 *  - Daily collections sat on a "roadmap" banner. They are the report a front
 *    desk actually needs at closing time, so they now lead the page.
 *  - The attendance card rendered hardcoded 92% and 78% figures that were pure
 *    invention and could never change, because nothing in the system captures
 *    attendance. Inventing numbers on a financial report is worse than omitting
 *    them, so it is gone until attendance capture exists.
 *  - Export raised a toast and produced no file. It now streams a real .xlsx.
 */
new class extends Component {
    /**
     * The selected preset KEY. Deliberately not named `$period`: `with()`
     * returns the resolved Period object under that name, and a Livewire public
     * property always shadows a `with()` key of the same name, so the view would
     * silently receive the string instead of the object.
     */
    public string $periodKey = 'month';

    public string $from = '';

    public string $to = '';

    public function setPeriod(string $key): void
    {
        $this->periodKey = array_key_exists($key, Period::PRESETS) ? $key : 'month';
    }

    public function with(): array
    {
        $period = Period::resolve($this->periodKey, $this->from ?: null, $this->to ?: null);

        return app(Reporting::class)->screen(auth()->user(), $period) + [
            'period' => $period,
            // The SELECTED key, which is not always the resolved one: picking
            // "Custom" before typing dates resolves to the month as a fallback.
            // The control must reflect the choice, or "Custom" can never be
            // selected long enough to reveal its own date inputs.
            'selectedKey' => $this->periodKey,
            'presets' => Period::PRESETS,
        ];
    }
}; ?>

{{-- Shared with the super admin's Reports tab, so the two cannot drift apart. --}}
@include('partials.reports-body', ['exportRoute' => 'reports.export'])
