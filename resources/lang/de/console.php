<?php

return [
    'swept' => ':count abgelaufene(s) Bestätigungs-Token gelöscht.',
    'migrated' => ':moved Zugang/Zugänge nach entitlements übernommen, :skipped übersprungen.',
    'migrate_dry_run' => 'Probelauf: :moved Zugang/Zugänge würden umziehen, :skipped würden übersprungen. Es wurde nichts geschrieben.',
    'migrate_nothing' => 'Keine Zugänge mehr zu übernehmen.',
    'migrate_already_done' => 'Die alten Status-Spalten sind bereits entfernt: diese Migration ist gelaufen.',
    'migrate_orphan' => 'Zugang :id zeigt auf eine Ressource, die es nicht mehr gibt. Übersprungen.',
    'migrated_revocation' => 'In lead-magnets 1.x am :at entzogen, übernommen durch lead-magnets:migrate-grants.',
];
