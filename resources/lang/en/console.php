<?php

return [
    'swept' => ':count expired confirmation token(s) cleared.',
    'migrated' => 'Moved :moved grant(s) into entitlements, skipped :skipped.',
    'migrate_dry_run' => 'Dry run: :moved grant(s) would move, :skipped would be skipped. Nothing was written.',
    'migrate_nothing' => 'No grants left to move.',
    'migrate_already_done' => 'The legacy grant state columns are gone: this migration has already run.',
    'migrate_orphan' => 'Grant :id points at a resource that no longer exists. Skipped.',
    'migrate_revoke_failed' => 'Grant :id was carried over, but its revocation did not go through. Check it in entitlements.',
    'migrate_failed' => ':failed grant(s) could not be revoked. The run is marked as failed.',
    'migrated_revocation' => 'Revoked in lead-magnets 1.x on :at, carried over by lead-magnets:migrate-grants.',
];
