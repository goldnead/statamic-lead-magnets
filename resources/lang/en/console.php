<?php

return [
    'swept' => ':count expired confirmation token(s) cleared.',
    'migrated' => 'Moved :moved grant(s) into entitlements, skipped :skipped.',
    'migrate_dry_run' => 'Dry run: :moved grant(s) would move, :skipped would be skipped. Nothing was written.',
    'migrate_nothing' => 'No grants left to move.',
    'migrate_already_done' => 'The legacy grant state columns are gone: this migration has already run.',
    'migrate_orphan' => 'Grant :id points at a resource that no longer exists. Skipped.',
    'migrated_revocation' => 'Revoked in lead-magnets 1.x on :at, carried over by lead-magnets:migrate-grants.',
];
