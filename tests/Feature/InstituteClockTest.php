<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ReportBook;
use App\Support\Clock;
use App\Support\Format;
use App\Support\Period;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The institute reads its clock in Lahore; the database keeps it in UTC.
 *
 * A report generated at 15:44 PKT printed "10:44", because the stored instant
 * went straight to the formatter with nothing converting it. These lock in the
 * conversion — and, just as importantly, lock in what did NOT change: storage
 * and day-bucketing both stay on UTC, because moving them would shift figures
 * on a financial database to fix a label.
 */
class InstituteClockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['institute.timezone' => 'Asia/Karachi']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_storage_stays_utc(): void
    {
        // The whole conversion rests on this. If app.timezone ever becomes
        // Asia/Karachi, every timestamp already banked silently re-reads five
        // hours earlier and the money moves days.
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('+00:00', config('database.connections.mysql.timezone'));
    }

    public function test_a_time_is_displayed_on_the_institutes_clock(): void
    {
        // The exact instant from the report that started this.
        $stored = Carbon::parse('2026-09-05 10:44:00', 'UTC');

        $this->assertSame('05 Sep 2026 15:44', Format::dateTime($stored));
        $this->assertSame('PKT', Format::zone());
    }

    public function test_an_evening_instant_keeps_the_karachi_date(): void
    {
        // 19:00 UTC is already tomorrow in Lahore. Formatting the raw instant
        // printed the previous day.
        $stored = Carbon::parse('2026-09-05 20:30:00', 'UTC');

        $this->assertSame('06 Sep 2026', Format::date($stored));
        $this->assertSame('06 Sep 2026 01:30', Format::dateTime($stored));
    }

    public function test_a_plain_date_is_never_dragged_backwards(): void
    {
        // `due_date` and friends are `date` casts: 00:00 UTC. The offset is
        // positive, so they can only ever move forward WITHIN their own day —
        // never over a midnight into the day before.
        $this->assertSame('01 Aug 2026', Format::date(Carbon::parse('2026-08-01', 'UTC')));
        $this->assertSame('01 Aug 2026', Format::date('2026-08-01'));
    }

    public function test_null_and_empty_stay_empty(): void
    {
        $this->assertSame('', Format::date(null));
        $this->assertSame('', Format::date(''));
        $this->assertSame('', Format::dateTime(null));
        $this->assertNull(Clock::local(null));
    }

    public function test_day_bucketing_was_deliberately_left_on_utc(): void
    {
        // Checked against production before this was written: 0 of 487 payments
        // fall between 00:00 and 05:00 PKT, the only window where the two
        // answers differ. Shifting the boundary would have changed reported
        // figures to fix nothing, so Clock::today() still returns the UTC day.
        // The suite pins institute.today so the demo reproduces the prototype's
        // figures; unpin it here to see what the real clock would answer.
        config(['institute.today' => null]);
        Carbon::setTestNow(Carbon::parse('2026-09-05 20:30:00', 'UTC'));

        $this->assertSame('2026-09-05', Clock::today()->toDateString());
        $this->assertSame('UTC', Clock::today()->timezone->getName());

        // ...while the same instant reads as the 6th on the wall clock.
        $this->assertSame('2026-09-06', Clock::now()->toDateString());
    }

    public function test_a_window_edge_is_not_carried_into_the_next_day(): void
    {
        // The regression this class was extended for. A period holds its end as
        // 23:59:59 — the last moment of the 30th, not an instant anyone lived
        // through. Converting it to the institute's clock rolled it into the
        // 1st, and the quarter printed as a day longer than a quarter.
        config(['institute.today' => '2026-09-05']);

        $this->assertSame('01 Jul 2026 to 30 Sep 2026', Period::resolve('quarter')->rangeLabel());
        $this->assertSame('01 Sep 2026 to 30 Sep 2026', Period::resolve('month')->rangeLabel());
        $this->assertSame('01 Jan 2026 to 31 Dec 2026', Period::resolve('year')->rangeLabel());
        $this->assertSame('05 Sep 2026', Period::resolve('today')->rangeLabel());
    }

    public function test_a_report_says_which_clock_it_was_generated_on(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:44:00', 'UTC'));

        $this->seed(DatabaseSeeder::class);
        $user = User::where('username', 'adminansar')->firstOrFail();
        $preamble = collect(app(ReportBook::class)->build($user, Period::resolve('quarter')))
            ->firstWhere('title', 'Summary')['preamble'];

        $this->assertContains('Generated: 05 Sep 2026 15:44 PKT', $preamble);
        $this->assertContains('Report period: This quarter (01 Jul 2026 to 30 Sep 2026)', $preamble);
    }

    public function test_a_pinned_demo_date_still_wins(): void
    {
        config(['institute.today' => '2026-07-15']);

        $this->assertSame('2026-07-15', Clock::today()->toDateString());
    }

    public function test_the_zone_follows_configuration(): void
    {
        // Read from the zone, not written down, so the label can never claim a
        // zone the timestamps are not actually in.
        config(['institute.timezone' => 'UTC']);

        $this->assertSame('UTC', Format::zone());
        $this->assertSame('05 Sep 2026 10:44', Format::dateTime(Carbon::parse('2026-09-05 10:44:00', 'UTC')));
    }
}
