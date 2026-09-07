<?php

namespace Goldnead\LeadMagnets\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;

/**
 * Was ein Betreiber an diesem Addon aus dem Control Panel heraus aendern darf.
 *
 * Diese Klasse ist **nur** die Feldliste. Seite, Formular, Validierung,
 * Speicher, Rechtepruefung und die Marken-Dimension kommen aus
 * `goldnead/statamic-brand-context` (siehe {@see ProvidesSettings}). Kein
 * eigener Controller, keine eigene Vue-Seite, keine eigene Tabelle.
 *
 * **Ueberschreibungen, keine Kopie.** Gespeichert wird nur, was jemand
 * wirklich geaendert hat; alles andere folgt weiter `config/lead-magnets.php`.
 * Ein Update des Pakets verschiebt also weiter die Vorgaben.
 *
 * **Was nicht hier steht, und warum.** Die Regel ist hart: was beim Booten
 * gelesen wird, gehoert nicht auf diese Seite. `SettingsManager::apply()`
 * laeuft aus `app->booted()`; ein Schalter, der erst beim naechsten Deploy
 * wirkt, ist eine Luege in der Oberflaeche.
 *
 * - `routes.prefix` (`routes/web.php:22`) und `requests.throttle`
 *   (`routes/web.php:30`) werden beim Registrieren der Routen gelesen.
 * - `assets.container` und `assets.disk` (`src/ServiceProvider.php:118`,
 *   `src/Support/MagnetAssets.php`) bestimmen, wo die Dateien liegen. Die
 *   Platte wird beim Booten definiert, und eine Umstellung unter laufendem
 *   Betrieb muesste die Dateien zuerst umziehen. `delivery.disk` ist derselbe
 *   Fall.
 * - `entitlements.source` und `entitlements.subject_type` sind laut Config
 *   Install-Zeit-Werte: beide sind Teil des eindeutigen Schluessels in
 *   entitlements, und eine Aenderung laesst jede bestehende Freigabe
 *   verwaisen.
 */
class Settings implements ProvidesSettings
{
    /**
     * Fest fuer immer: der Wert steht in `brand_settings.namespace` auf jeder
     * Zeile, ein neuer Name liesse jede Ueberschreibung des Sites verwaisen.
     */
    public static function settingsNamespace(): string
    {
        return 'lead-magnets';
    }

    /** Die Config-Wurzel, der ungesetzte Werte weiter folgen. */
    public static function settingsConfigPath(): string
    {
        return 'lead-magnets';
    }

    /**
     * Neu mit dieser Seite, deshalb nach der Regel der Suite gebildet:
     * `manage <handle> settings`, mit dem Paketnamen ohne `statamic-`-Praefix.
     *
     * Die bestehenden Rechte (`view lead magnets`, `manage lead magnets`,
     * `manage lead magnet grants`) bleiben unberuehrt — sie umzubenennen waere
     * ein stiller Rechteentzug auf jeder Installation, die sie vergeben hat.
     */
    public static function settingsPermission(): string
    {
        return 'manage lead-magnets settings';
    }

    /**
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('lead-magnets::settings.groups.delivery.title'),
                'description' => __('lead-magnets::settings.groups.delivery.description'),
                'fields' => [
                    static::field('delivery.link_ttl', 'integer', ['min' => 1]),
                    // `nullable` ist hier kein Beiwerk: leer heisst „kein
                    // Deckel", und das ist ein anderer Zustand als eine Zahl.
                    static::field('delivery.max_downloads', 'integer', ['min' => 1, 'nullable' => true]),
                    static::field('delivery.grant_ttl_days', 'integer', ['min' => 1, 'nullable' => true]),
                ],
            ],
            [
                'title' => __('lead-magnets::settings.groups.requests.title'),
                'description' => __('lead-magnets::settings.groups.requests.description'),
                'fields' => [
                    static::field('requests.confirmation_ttl_hours', 'integer', ['min' => 1]),
                    static::field('requests.honeypot', 'string'),
                ],
            ],
            [
                'title' => __('lead-magnets::settings.groups.mail.title'),
                'description' => __('lead-magnets::settings.groups.mail.description'),
                'fields' => [
                    static::field('mail.confirmation_template', 'string'),
                    static::field('mail.delivery_template', 'string'),
                ],
            ],
            [
                'title' => __('lead-magnets::settings.groups.integrations.title'),
                'description' => __('lead-magnets::settings.groups.integrations.description'),
                'fields' => [
                    static::field('integrations.leadhub', 'boolean'),
                    static::field('integrations.marketing', 'boolean'),
                    static::field('integrations.email_templates', 'boolean'),
                    static::field('integrations.suppression', 'boolean'),
                    static::field('integrations.activity', 'boolean'),
                    static::field('integrations.insights', 'boolean'),
                ],
            ],
        ];
    }

    /**
     * Ein Feld, mit Beschriftung und Erklaerung aus den Sprachdateien.
     *
     * Der Sprachschluessel ist der Config-Pfad mit ersetzten Punkten: ein Punkt
     * im Sprachschluessel ist fuer den Uebersetzer ein Pfadtrenner, und
     * `settings.fields.delivery.link_ttl.label` wuerde als verschachteltes
     * Array gesucht, das es nicht gibt.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function field(string $key, string $type, array $extra = []): array
    {
        $handle = str_replace('.', '_', $key);

        return array_merge([
            'key' => $key,
            'type' => $type,
            'label' => __("lead-magnets::settings.fields.{$handle}.label"),
            'description' => __("lead-magnets::settings.fields.{$handle}.description"),
            'nullable' => false,
        ], $extra);
    }
}
