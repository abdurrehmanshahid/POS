<?php

use App\Services\Reporting;
use App\Support\InstituteWideViewer;
use App\Support\Period;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Reports, for the super admin.
 *
 * The staff Reports screen, figure for figure, but always institute-wide: the
 * super admin is not permission-scoped (see SuperNav), so it reads through an
 * InstituteWideViewer rather than any one officer's view.
 */
new #[Layout('components.layouts.super')] class extends Component {
    /** The selected preset KEY. See pages/reports for why it is not `$period`. */
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

        return app(Reporting::class)->screen(new InstituteWideViewer, $period) + [
            'period' => $period,
            'selectedKey' => $this->periodKey,
            'presets' => Period::PRESETS,
        ];
    }
}; ?>

@include('partials.reports-body', ['exportRoute' => 'superadmin.reports.export'])
