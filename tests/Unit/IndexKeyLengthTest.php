<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Measures the composite keys this addon adds, in bytes, against InnoDB's
 * 3072-byte limit for a single index.
 *
 * The failure this exists to prevent took statamic-notifications' migrations
 * down: a suite that was fully green on SQLite, and a `CREATE INDEX` that
 * MySQL refused to build on the first real install. SQLite has no key length
 * limit and no utf8mb4 byte arithmetic, so it can never see it. This test does
 * the arithmetic itself and therefore runs everywhere; `phpunit.mysql.xml` is
 * the second opinion from a real server.
 */

/** InnoDB, utf8mb4: four bytes per character, plus eight for a BIGINT. */
function keyBytes(array $columns): int
{
    $bytes = 0;

    foreach ($columns as $column) {
        $bytes += is_int($column) ? 8 : $column * 4;
    }

    return $bytes;
}

it('keeps the grants uniqueness key inside the InnoDB limit', function () {
    // (brand_id BIGINT, resource_id BIGINT, email VARCHAR(191))
    expect(keyBytes([1, 1, 191]))->toBeLessThan(3072);
});

it('keeps the resource handle unique inside the InnoDB limit', function () {
    // (handle VARCHAR(191)) — global, not per brand. See the migration.
    expect(keyBytes([191]))->toBeLessThan(3072);
});

it('declares the columns at the widths the arithmetic above assumes', function () {
    // The numbers above are only worth anything while the schema matches them.
    $columns = Schema::getColumns('lead_magnet_grants');

    $byName = collect($columns)->keyBy('name');

    expect($byName['email']['type'])->toContain('191');

    expect(collect(Schema::getColumns('lead_magnet_resources'))->keyBy('name')['handle']['type'])
        ->toContain('191');
})->skip(fn () => DB::connection()->getDriverName() !== 'mysql', 'Column widths are only expressed in the type on MySQL.');
