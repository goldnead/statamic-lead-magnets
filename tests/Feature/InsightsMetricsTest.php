<?php

namespace Goldnead\LeadMagnets\Tests\Feature;

use Goldnead\BrandContext\Models\Brand;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\LeadMagnets\Integrations\Insights\Confirmed;
use Goldnead\LeadMagnets\Integrations\Insights\ConfirmRate;
use Goldnead\LeadMagnets\Integrations\Insights\Downloads;
use Goldnead\LeadMagnets\Integrations\Insights\LeadMagnetMetric;
use Goldnead\LeadMagnets\Integrations\Insights\Requested;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Services\GrantService;
use Goldnead\LeadMagnets\Tests\TestCase;
use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Facades\Insights as InsightsStandIn;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\TableMetric;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The four numbers this addon offers the analytics addon.
 *
 * **The fixture is built through the real service, not seeded.** Every request,
 * confirmation and download below goes through `GrantService`, with the clock
 * moved to the moment it is supposed to have happened. A seeded fixture would
 * have to invent the very columns whose meaning is under test — and one of them
 * does not exist in this package any more: confirmation lives on the
 * entitlement, and only `claimPending()` writes it.
 *
 * Tested against a stand-in for the analytics contract rather than the real
 * package: it is a `suggest`, and a test that needed it installed would be
 * proving the opposite of what this addon claims.
 * `tests/Fakes/insights-contracts.php` explains why that is a required file and
 * not an autoload entry, and `InsightsContractsMatchTest` is what holds the copy
 * to account.
 *
 * A PHPUnit class rather than a Pest file, for one mechanical reason: the
 * stand-ins have to be loaded **before the application boots** — the contracts
 * before a metric class is touched, the facade before the provider's `booted()`
 * callback asks whether it exists. `beforeEach()` runs after the bed is up, and
 * a callback that has already run cannot be given a second chance.
 */
class InsightsMetricsTest extends TestCase
{
    /** The day everything below is measured from. */
    protected const HEUTE = '2026-08-20 12:00:00';

    /** Collects what the service provider registers. */
    protected object $insights;

    protected Resource $warmUp;

    protected Resource $atmung;

    protected function setUp(): void
    {
        require_once __DIR__.'/../Fakes/insights-contracts.php';

        // The base class lies beside them as a file of its own and carries no
        // guard in its head: it is a byte-for-byte copy of the original, so the
        // guard sits here instead. See InsightsContractsMatchTest.
        if (! class_exists(TableMetric::class, false)) {
            require_once __DIR__.'/../Fakes/insights-table-metric.php';
        }

        require_once __DIR__.'/../Fakes/insights-facade.php';

        $this->insights = new class
        {
            /** @var array<string, string> */
            public array $registered = [];

            /**
             * Stricter than the real manager on purpose.
             *
             * The genuine one accepts a metric without a handle and works one
             * out by constructing it. Accepting that here would let the
             * provider drop the handle and still look correct — and the handle
             * is the half that ends up in saved dashboards and in URLs.
             */
            public function registerMetric(string|Metric|\Closure $metric, ?string $handle = null): void
            {
                if (! is_string($metric) || $handle === null) {
                    throw new \InvalidArgumentException('This addon registers metrics lazily: a class name and a handle.');
                }

                $this->registered[$handle] = $metric;
            }
        };

        InsightsStandIn::$root = $this->insights;

        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::HEUTE));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        InsightsStandIn::$root = null;

        parent::tearDown();
    }

    // -- The fixture --------------------------------------------------------

    /**
     * Two resources, six requests, four confirmations, four downloads.
     *
     * | who    | resource | asked | confirmed |
     * |--------|----------|-------|-----------|
     * | anna   | warm-up  | 08-12 | 08-12     |
     * | bruno  | warm-up  | 08-13 | never     |
     * | anna   | atmung   | 08-14 | 08-15     |
     * | clara  | atmung   | 08-16 | 08-16     |
     * | erik   | atmung   | 08-18 | never     |
     * | dora   | warm-up  | 07-02 | 07-03     |
     *
     * Downloads: anna twice on 08-17, clara on 08-19, dora on 07-05.
     */
    protected function fixture(): void
    {
        $this->warmUp = $this->resource('warm_up', 'Warm-up routine');
        $this->atmung = $this->resource('atmung', 'Atemübungen');

        $anna = $this->requestAt('2026-08-12 09:00:00', $this->warmUp, 'anna@example.com');
        $this->confirmAt('2026-08-12 10:00:00', $anna);

        // Asked and never came back. The entitlement stays pending, which means
        // `starts_at` stays null — and that null is what separates the two
        // figures.
        $this->requestAt('2026-08-13 09:00:00', $this->warmUp, 'bruno@example.com');

        $annaZwei = $this->requestAt('2026-08-14 09:00:00', $this->atmung, 'anna@example.com');
        $this->confirmAt('2026-08-15 09:00:00', $annaZwei);

        $clara = $this->requestAt('2026-08-16 09:00:00', $this->atmung, 'clara@example.com');
        $this->confirmAt('2026-08-16 10:00:00', $clara);

        $this->requestAt('2026-08-18 09:00:00', $this->atmung, 'erik@example.com');

        // Six weeks earlier, and in no figure of this period.
        $dora = $this->requestAt('2026-07-02 09:00:00', $this->warmUp, 'dora@example.com');
        $this->confirmAt('2026-07-03 09:00:00', $dora);

        $this->downloadAt('2026-08-17 11:00:00', $anna);
        $this->downloadAt('2026-08-17 15:00:00', $anna);
        $this->downloadAt('2026-08-19 11:00:00', $clara);
        $this->downloadAt('2026-07-05 11:00:00', $dora);
    }

    protected function resource(string $handle, string $title): Resource
    {
        return Resource::query()->create([
            'handle' => $handle,
            'title' => $title,
            'delivery_type' => Resource::TYPE_LINK,
            'link_url' => 'https://example.test/'.$handle,
            'requires_confirmation' => true,
            'published' => true,
        ]);
    }

    /** A real request, at a stated moment. */
    protected function requestAt(string $moment, Resource $resource, string $email): Grant
    {
        return $this->at($moment, fn () => app(GrantService::class)->request($resource, $email));
    }

    /** A real confirmation, at a stated moment. */
    protected function confirmAt(string $moment, Grant $grant): void
    {
        $this->at($moment, fn () => app(GrantService::class)->activate($grant->refresh()));
    }

    protected function downloadAt(string $moment, Grant $grant): void
    {
        $this->at($moment, fn () => app(GrantService::class)->recordDownload($grant->refresh()));
    }

    /** Run a closure with the clock moved, then put it back. */
    protected function at(string $moment, \Closure $tun): mixed
    {
        Carbon::setTestNow(Carbon::parse($moment));

        try {
            return $tun();
        } finally {
            Carbon::setTestNow(Carbon::parse(self::HEUTE));
        }
    }

    /** The ten days the fixture lives in. */
    protected function frage(string $bucket = MetricQuery::BUCKET_DAY): MetricQuery
    {
        return new MetricQuery(
            Period::between(
                Carbon::parse('2026-08-11')->startOfDay(),
                Carbon::parse('2026-08-20')->endOfDay(),
            ),
            $bucket,
        );
    }

    /** @return array<string, int|float> */
    protected function keyed(array $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[$row['key'] ?? ''] = $row['value'];
        }

        return $keyed;
    }

    // -- The four numbers ---------------------------------------------------

    /**
     * Every figure at once, against hand-worked totals.
     *
     * One test rather than four: they are read side by side on a screen and the
     * rate is literally two of the others divided. A confirmation count that
     * drifted without the rate following it is the failure worth catching, and
     * four separate tests are four chances to fix one and leave the rest
     * disagreeing.
     */
    #[Test]
    public function the_four_figures_match_what_the_fixture_says(): void
    {
        $this->fixture();
        $frage = $this->frage();

        $this->assertSame(5, (new Requested)->value($frage), 'dora asked in July and is outside');
        $this->assertSame(3, (new Confirmed)->value($frage), 'bruno and erik never came back');
        $this->assertSame(3, (new Downloads)->value($frage), 'anna twice, clara once');
        $this->assertSame(60.0, (new ConfirmRate)->value($frage), '3 of 5');
    }

    /** The handles are a contract. They end up in saved dashboards and in URLs. */
    #[Test]
    public function the_handles_and_units_are_the_ones_that_were_promised(): void
    {
        $erwartet = [
            [Requested::class, 'lead_magnets.requested', Unit::COUNT],
            [Confirmed::class, 'lead_magnets.confirmed', Unit::COUNT],
            [Downloads::class, 'lead_magnets.downloads', Unit::COUNT],
            [ConfirmRate::class, 'lead_magnets.confirm_rate', Unit::PERCENT],
        ];

        foreach ($erwartet as [$klasse, $handle, $unit]) {
            /** @var LeadMagnetMetric $metrik */
            $metrik = new $klasse;

            $this->assertSame($handle, $metrik->handle());
            $this->assertSame($unit, $metrik->unit());
            $this->assertSame(__('lead-magnets::insights.metric_group'), $metrik->group());

            // Translated, not hard-coded: a missing key comes back as the key
            // itself, which is what these assertions catch.
            $this->assertNotSame('', $metrik->label());
            $this->assertStringNotContainsString('insights.metric_', (string) $metrik->label());
            $this->assertNotEmpty($metrik->description());
            $this->assertStringNotContainsString('insights.metric_', (string) $metrik->description());

            $this->assertSame([], $metrik->meta($this->frage()));
        }
    }

    // -- The rate -----------------------------------------------------------

    /**
     * A rate against nothing is a question, not a small number.
     *
     * Nobody asked in this period, so there is no denominator and therefore no
     * rate. Printing 0 % would sit on the screen beside a request count of zero
     * that contradicts it, and a reader would take it for a very bad month
     * rather than an empty one.
     */
    #[Test]
    public function the_rate_is_null_rather_than_zero_when_nobody_asked(): void
    {
        $this->fixture();

        $leer = new MetricQuery(Period::between(
            Carbon::parse('2025-01-01')->startOfDay(),
            Carbon::parse('2025-01-31')->endOfDay(),
        ));

        $this->assertSame(0, (new Requested)->value($leer));
        $this->assertSame(0, (new Confirmed)->value($leer));
        $this->assertNull((new ConfirmRate)->value($leer), 'no denominator, no rate');

        // The counts beside it do answer, because "nobody asked" is an answer to
        // what they ask.
        $this->assertSame([], (new Requested)->series($leer));
        $this->assertSame([], (new ConfirmRate)->series($leer));
    }

    /**
     * And per bucket, which is where the distinction earns its keep.
     *
     * The 15th holds a confirmation and no request at all — anna confirmed a day
     * after she asked. A rate there is not zero and not a hundred; it is a
     * question that does not apply, and `null` is the only honest answer. The
     * contract keeps it null all the way to the screen, which draws no bar
     * rather than a bar of nothing.
     */
    #[Test]
    public function the_rate_series_is_null_on_every_bucket_with_no_requests(): void
    {
        $this->fixture();

        $this->assertSame([
            '2026-08-11' => null,
            '2026-08-12' => 100.0,
            '2026-08-13' => 0.0,
            '2026-08-14' => 0.0,
            // anna confirmed on the 15th what she asked for on the 14th.
            '2026-08-15' => null,
            '2026-08-16' => 100.0,
            '2026-08-17' => null,
            '2026-08-18' => 0.0,
            '2026-08-19' => null,
            '2026-08-20' => null,
        ], (new ConfirmRate)->series($this->frage()));
    }

    /**
     * The rate agrees with the two tiles beside it, always.
     *
     * It is computed from those two metrics rather than from a query of its
     * own, precisely so it cannot drift from them — a rate whose numerator
     * disagrees with the count next to it makes a reader distrust all three.
     */
    #[Test]
    public function the_rate_is_exactly_the_two_figures_beside_it(): void
    {
        $this->fixture();
        $frage = $this->frage();

        $this->assertSame(
            round((int) (new Confirmed)->value($frage) / (int) (new Requested)->value($frage) * 100, 1),
            (new ConfirmRate)->value($frage),
        );
    }

    // -- Nothing to measure -------------------------------------------------

    /**
     * No tables, no answer — and not a zero.
     *
     * "Nothing to measure" and "measured nothing" are different statements, and
     * a zero for the first is the quiet kind of wrong: it puts a confident 0 on
     * a dashboard for a site that has never published a freebie.
     */
    #[Test]
    public function a_metric_cannot_answer_without_its_tables(): void
    {
        foreach ([Requested::class, Confirmed::class, Downloads::class, ConfirmRate::class] as $klasse) {
            $this->assertTrue((new $klasse)->available());
        }

        // A second, empty database rather than dropping the tables in this one.
        // Dropping them would leave the suite unable to roll its own migrations
        // back, and a test that breaks its neighbours' teardown reports the
        // wrong failure everywhere afterwards.
        config()->set('database.connections.ohne_freebies', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $vorher = DB::getDefaultConnection();
        DB::purge('ohne_freebies');
        DB::setDefaultConnection('ohne_freebies');

        try {
            foreach ([Requested::class, Confirmed::class, Downloads::class, ConfirmRate::class] as $klasse) {
                $metrik = new $klasse;

                $this->assertFalse($metrik->available(), $klasse.' answered without its tables.');
                $this->assertNull($metrik->value($this->frage()), $klasse.' produced a figure without its tables.');
                $this->assertSame([], $metrik->series($this->frage()));
            }
        } finally {
            DB::setDefaultConnection($vorher);
        }
    }

    /** And the same when the bridge is switched off, tables or no tables. */
    #[Test]
    public function the_bridge_can_be_switched_off_without_uninstalling_anything(): void
    {
        $this->fixture();

        config()->set('lead-magnets.integrations.insights', false);

        foreach ([Requested::class, Confirmed::class, Downloads::class, ConfirmRate::class] as $klasse) {
            $metrik = new $klasse;

            $this->assertFalse($metrik->available(), $klasse.' ignored the switch.');
            $this->assertNull($metrik->value($this->frage()));
            $this->assertSame([], $metrik->series($this->frage()));
        }

        $this->assertSame([], (new Requested)->breakdown($this->frage(), 'resource'));
    }

    // -- The split ----------------------------------------------------------

    /**
     * Requests split by resource, and named by their title.
     *
     * `4` is a number a reader cannot act on. The id is what goes in the URL,
     * which is what `key` is for; `label` is what goes on the screen.
     */
    #[Test]
    public function requests_split_by_resource_and_are_named_by_their_title(): void
    {
        $this->fixture();

        $zeilen = (new Requested)->breakdown($this->frage(), 'resource');

        $this->assertSame(
            [(string) $this->atmung->id => 3, (string) $this->warmUp->id => 2],
            $this->keyed($zeilen),
        );

        $this->assertSame(5, array_sum(array_column($zeilen, 'value')), 'the split must add up to the figure it splits');

        $this->assertSame('Atemübungen', $zeilen[0]['label']);
        $this->assertSame('Warm-up routine', $zeilen[1]['label']);
    }

    /**
     * A resource that has since been deleted keeps its id as its label.
     *
     * The requests happened. Dropping the row because nobody can name it any
     * more would make the split disagree with the total, and nothing on the
     * screen would say why.
     */
    #[Test]
    public function a_deleted_resource_keeps_its_place_in_the_split(): void
    {
        $this->fixture();

        $id = (string) $this->warmUp->id;

        DB::table('lead_magnet_resources')->where('id', $id)->delete();

        $zeilen = $this->keyed((new Requested)->breakdown($this->frage(), 'resource'));

        $this->assertSame(2, $zeilen[$id] ?? null);

        $etiketten = [];

        foreach ((new Requested)->breakdown($this->frage(), 'resource') as $zeile) {
            $etiketten[$zeile['key']] = $zeile['label'];
        }

        $this->assertSame($id, $etiketten[$id], 'an unnameable resource is shown by its id, not dropped');
    }

    /** A split nobody offers is empty, not an error. */
    #[Test]
    public function an_unknown_split_is_empty(): void
    {
        $this->fixture();

        $this->assertSame([], (new Requested)->breakdown($this->frage(), 'weather'));
        $this->assertSame(['resource'], array_keys((new Requested)->breakdowns()));
    }

    /** Largest first, and no more than asked for. */
    #[Test]
    public function a_split_is_ordered_by_size_and_respects_the_limit(): void
    {
        $this->fixture();

        $zeilen = (new Requested)->breakdown($this->frage(), 'resource', 1);

        $this->assertCount(1, $zeilen);
        $this->assertSame((string) $this->atmung->id, $zeilen[0]['key']);
    }

    // -- Over time ----------------------------------------------------------

    /**
     * A count series holds only the buckets something happened in.
     *
     * The empty days are Insights' job — it fills the range for every metric at
     * once. A metric that filled its own would be filling them twice. The rate
     * is the exception and says why in its own test above: an omitted bucket
     * becomes a zero, and a zero per cent is a false statement rather than an
     * empty one.
     */
    #[Test]
    public function a_count_series_returns_only_the_buckets_that_have_data(): void
    {
        $this->fixture();
        $frage = $this->frage();

        $this->assertSame(
            ['2026-08-12' => 1, '2026-08-13' => 1, '2026-08-14' => 1, '2026-08-16' => 1, '2026-08-18' => 1],
            (new Requested)->series($frage),
        );

        $this->assertSame(
            ['2026-08-12' => 1, '2026-08-15' => 1, '2026-08-16' => 1],
            (new Confirmed)->series($frage),
        );

        $this->assertSame(
            ['2026-08-17' => 2, '2026-08-19' => 1],
            (new Downloads)->series($frage),
        );
    }

    /** The grain comes from the question, not from the period. */
    #[Test]
    public function a_monthly_question_gets_monthly_buckets(): void
    {
        $this->fixture();

        $frage = $this->frage(MetricQuery::BUCKET_MONTH);

        $this->assertSame(['2026-08' => 5], (new Requested)->series($frage));
        $this->assertSame(['2026-08' => 3], (new Confirmed)->series($frage));
        $this->assertSame(['2026-08' => 60.0], (new ConfirmRate)->series($frage));
    }

    // -- A row with no timestamp is in no period -----------------------------

    /**
     * The widest period passes no bounds at all, which is where a nullable
     * column stops being harmless.
     *
     * All three columns counted here are nullable, and `NULL >= '2026-08-01'`
     * is never true — so on every ordinary preset the nulls fall out on their
     * own and nobody notices they were never excluded on purpose. On "all"
     * there is no comparison left to do the excluding, and a grant that was
     * never confirmed would be reported as confirmed.
     *
     * Bruno and Erik are exactly that: they asked and never came back, so their
     * entitlements are pending and their `starts_at` is null.
     */
    #[Test]
    public function a_row_without_a_timestamp_is_in_no_period_not_even_all_time(): void
    {
        $this->fixture();

        // A download nobody dated, and a grant nobody dated — the shapes an
        // older release or a botched import leaves behind.
        DB::table('lead_magnet_downloads')->insert([
            'brand_id' => 1,
            'grant_id' => Grant::query()->first()->id,
            'downloaded_at' => null,
            'created_at' => '2026-08-14 09:00:00',
            'updated_at' => '2026-08-14 09:00:00',
        ]);

        DB::table('lead_magnet_grants')->insert([
            'brand_id' => 1,
            'resource_id' => $this->warmUp->id,
            'email' => 'ohne-datum@example.com',
            'attempt' => 1,
            'requested_at' => null,
            'created_at' => '2026-08-14 09:00:00',
            'updated_at' => '2026-08-14 09:00:00',
        ]);

        $alles = new MetricQuery(Period::fromPreset('all'), MetricQuery::BUCKET_MONTH);

        $this->assertSame(
            2,
            (int) Entitlement::query()->withoutGlobalScopes()->whereNull('starts_at')->count(),
            'bruno and erik are meant to be pending',
        );

        $this->assertSame(6, (new Requested)->value($alles), 'the six real requests; the undated row is in no period');
        $this->assertSame(4, (new Confirmed)->value($alles), 'the two pending grants are not confirmations');
        $this->assertSame(4, (new Downloads)->value($alles), 'and the undated download is not a download of any month');
    }

    // -- The window belongs to the columns ------------------------------------

    /**
     * A window stated in another timezone is moved before it touches a column.
     *
     * Insights builds its period from `Carbon::now()`, so in practice the bounds
     * already arrive in the site's own zone and nothing here changes them. This
     * is the case where they do not — and it matters more for this addon than
     * for its siblings, because the rate's two halves live in tables that keep
     * time differently: the grants in the application timezone, the
     * entitlements in UTC. Bounds passed through unconverted would move the
     * numerator and the denominator by different amounts, and the rate would be
     * wrong in a way that looks entirely reasonable.
     */
    #[Test]
    public function a_window_stated_in_another_timezone_still_selects_the_right_rows(): void
    {
        $this->fixture();

        $berlin = new MetricQuery(Period::between(
            Carbon::parse('2026-08-11 00:00:00', 'UTC')->setTimezone('Europe/Berlin'),
            Carbon::parse('2026-08-20 23:59:59', 'UTC')->setTimezone('Europe/Berlin'),
        ));

        // The same ten days, named in a different zone. Same instants, same
        // answers.
        $this->assertSame(5, (new Requested)->value($berlin));
        $this->assertSame(3, (new Confirmed)->value($berlin));
        $this->assertSame(3, (new Downloads)->value($berlin));
        $this->assertSame(60.0, (new ConfirmRate)->value($berlin));
    }

    // -- A repeat request ------------------------------------------------------

    /**
     * Asking twice moves the row, and the earlier month loses it.
     *
     * The unique index is `(brand, resource, email)` and `request()` updates the
     * row it finds — asking again is a repeat, not a new lead. So this figure
     * counts grants **last** requested in the window, and a report of an earlier
     * month changes underneath somebody who comes back. It is the honest
     * reading of a table that keeps current state rather than a log; the
     * alternative would be to invent a request history this package does not
     * store. Named here so nobody has to discover it from a moving chart.
     */
    #[Test]
    public function asking_again_moves_the_request_out_of_the_month_it_was_first_made_in(): void
    {
        $this->fixture();

        $this->assertSame(1, (new Requested)->series($this->frage())['2026-08-12']);

        $this->requestAt('2026-08-20 09:00:00', $this->warmUp, 'anna@example.com');

        $reihe = (new Requested)->series($this->frage());

        $this->assertArrayNotHasKey('2026-08-12', $reihe, "anna's request now sits on the 20th");
        $this->assertSame(1, $reihe['2026-08-20']);
        $this->assertSame(5, (new Requested)->value($this->frage()), 'and the total is unchanged: it is still one grant');
    }

    /**
     * A confirmation survives a second access period, which a join would lose.
     *
     * `GrantService::reopen()` writes a **new** entitlement when an expired
     * grant is requested again and repoints the grant at it. The first
     * entitlement stays in the table as a true record of an access period that
     * happened, but nothing links to it any more — so a figure joined through
     * `entitlement_id` would drop the original confirmation out of the month it
     * happened in, retroactively. Counting by source keeps both.
     */
    #[Test]
    public function a_second_access_period_does_not_erase_the_first_confirmation(): void
    {
        $this->fixture();

        $this->assertSame(1, (new Confirmed)->series($this->frage())['2026-08-12']);

        // Anna's access runs out, and she asks again.
        $anna = Grant::query()->where('email', 'anna@example.com')->where('resource_id', $this->warmUp->id)->first();
        $anna->entitlement->forceFill(['expires_at' => '2026-08-19 09:00:00'])->save();

        $wieder = $this->requestAt('2026-08-20 09:00:00', $this->warmUp, 'anna@example.com');
        $this->confirmAt('2026-08-20 10:00:00', $wieder);

        $reihe = (new Confirmed)->series($this->frage());

        $this->assertSame(1, $reihe['2026-08-12'], 'the first confirmation is still in the month it happened in');
        $this->assertSame(1, $reihe['2026-08-20'], 'and the second is its own');
        $this->assertSame(4, (new Confirmed)->value($this->frage()));
    }

    // -- The last fraction of the last second --------------------------------

    /**
     * A request stamped at 23:59:59.500 on the closing day is inside the period.
     *
     * The defect these metrics stopped writing their own window over. A period's
     * `to` is 23:59:59.999999, a binding formats a date as `Y-m-d H:i:s` and
     * drops the fraction, and a `<=` comparison against a column that keeps
     * milliseconds therefore threw away every row in the period's final second.
     * Invisibly, and only where the fraction survives — which is why nobody's
     * suite went red over it.
     * {@see TableMetric::inPeriod()} compares
     * `< midnight` instead, and midnight is the same instant at every precision.
     *
     * The fraction is written past the model, because the cast that wrote the
     * row formats to whole seconds and the case could not otherwise be stated.
     */
    #[Test]
    public function a_request_in_the_last_fraction_of_the_final_second_is_counted(): void
    {
        $this->warmUp = $this->resource('warm_up', 'Warm-up routine');

        $this->requestAt('2026-08-19 12:00:00', $this->warmUp, 'mittag@example.com');
        $spaet = $this->requestAt('2026-08-19 20:00:00', $this->warmUp, 'kurz-vor-zwoelf@example.com');

        DB::table('lead_magnet_grants')
            ->where('id', $spaet->id)
            ->update(['requested_at' => '2026-08-19 23:59:59.500']);

        $tag = new MetricQuery(Period::between(
            Carbon::parse('2026-08-19')->startOfDay(),
            Carbon::parse('2026-08-19')->endOfDay(),
        ));

        $this->assertSame(2, (new Requested)->value($tag), 'the request half a second before midnight is inside the day');
        $this->assertSame(['2026-08-19' => 2], (new Requested)->series($tag), "and it is in that day's column, not the next one");
    }

    // -- Across brands ---------------------------------------------------------

    /**
     * The figures stop at the brand boundary.
     *
     * They did not, once, and the argument was that Insights asks its question
     * per installation. It sits badly beside the tiles that do narrow: a screen
     * on which one number counts this customer and the one next to it counts
     * every customer, with nothing saying which is which, shows one client's
     * traffic to another.
     *
     * All four figures are checked, `Confirmed` included — it reads the
     * sibling's `entitlements` table, which carries the same column, and a
     * confirmation rate whose halves narrowed differently would be nonsense in
     * a way nobody could see.
     */
    #[Test]
    public function the_figures_stop_at_a_brand_boundary(): void
    {
        $this->fixture();

        // Single-brand first, which is what every other expectation in this
        // file is measured under: nothing is narrowed at all.
        $this->assertSame(5, (new Requested)->value($this->frage()));

        config()->set('brand-context.multi_brand', true);
        app('brand-context')->forget();

        $eins = app('brand-context')->default();
        $zwei = Brand::query()->create(['handle' => 'zwei', 'name' => 'Zwei']);

        // One request and one confirmation belonging to the second brand,
        // inside the window and on a day the first brand also has traffic.
        app('brand-context')->runFor($zwei, function () {
            $fremd = $this->resource('fremd', 'Fremdes Freebie');
            $grant = $this->requestAt('2026-08-16 09:00:00', $fremd, 'fremd@example.com');
            $this->confirmAt('2026-08-16 10:00:00', $grant);
        });

        app('brand-context')->runFor($eins, function () {
            $this->assertSame(5, (new Requested)->value($this->frage()), "the other brand's request is not in this brand's figure");
            $this->assertSame(3, (new Confirmed)->value($this->frage()), 'and neither is its confirmation');
            $this->assertSame(3, (new Downloads)->value($this->frage()));
            $this->assertSame(60.0, (new ConfirmRate)->value($this->frage()), 'the rate is still 3 of 5');
        });

        app('brand-context')->runFor($zwei, function () {
            $this->assertSame(1, (new Requested)->value($this->frage()), 'and the second brand sees its own and nothing else');
            $this->assertSame(1, (new Confirmed)->value($this->frage()));
            $this->assertSame(0, (new Downloads)->value($this->frage()));
            $this->assertSame(100.0, (new ConfirmRate)->value($this->frage()));
        });
    }

    /**
     * With brand isolation on and no brand resolved, the figures are empty
     * rather than everything.
     *
     * The same choice `BrandScope` makes, and for the same reason: a report that
     * falls back to "all brands" the moment it cannot tell which one it is
     * looking at is precisely the failure that leaks. A tile reading zero can be
     * understood.
     */
    #[Test]
    public function an_unresolved_brand_counts_nothing(): void
    {
        $this->fixture();

        config()->set('brand-context.multi_brand', true);
        app('brand-context')->forget();

        $this->assertSame(0, (new Requested)->value($this->frage()));
        $this->assertSame(0, (new Confirmed)->value($this->frage()));
        $this->assertSame(0, (new Downloads)->value($this->frage()));
        $this->assertNull((new ConfirmRate)->value($this->frage()), 'no requests at all is no rate, not nought per cent');
    }

    // -- The wiring ------------------------------------------------------------

    /**
     * The provider hands all four to the sibling, lazily and by handle.
     *
     * By class name rather than instance, so booting this addon does not build
     * four metric objects on a request that renders none of them.
     */
    #[Test]
    public function the_service_provider_offers_every_metric_to_the_sibling(): void
    {
        $this->assertSame([
            'lead_magnets.requested' => Requested::class,
            'lead_magnets.confirmed' => Confirmed::class,
            'lead_magnets.downloads' => Downloads::class,
            'lead_magnets.confirm_rate' => ConfirmRate::class,
        ], $this->insights->registered);
    }
}
