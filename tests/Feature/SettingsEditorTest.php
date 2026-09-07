<?php

/**
 * Die Einstellungsseite dieses Addons — der Abschnitt auf dem gemeinsamen
 * Bildschirm von `goldnead/statamic-brand-context`.
 *
 * Die Tests gehen bewusst ueber HTTP statt direkt an den Manager. Was von
 * dieser Seite aus zu beweisen ist, ist dass Lead Magnets ueberhaupt
 * **angemeldet** ist: ein implementierter Vertrag, den niemand anmeldet, ist
 * eine Seite ohne diesen Abschnitt — und jede Behauptung gegen den Manager
 * ginge trotzdem durch.
 *
 * Und: `config()` ist kein Beleg. Ein gespeicherter Wert gilt erst als
 * angekommen, wenn ihn der Leser sieht, der ihn im Betrieb liest — die
 * Link-Frist beim Modell, das Fallenfeld im oeffentlichen Formular, der
 * Schalter in der Bruecke.
 */

use Goldnead\BrandContext\Models\BrandSetting;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\LeadMagnets\Integrations\LeadhubBridge;
use Goldnead\LeadMagnets\Integrations\SiblingBridges;
use Goldnead\LeadMagnets\LeadMagnetsManager;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Support\Settings;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeLeadHubFacade;
use Goldnead\LeadMagnets\Tests\Fixtures\SiblingStubs;
use Statamic\Facades\User;

beforeEach(function (): void {
    $this->user = User::make()->email('lead-magnet-settings@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);
});

/** Jedes Feld, das dieses Addon anbietet, so wie die Registry es sieht. */
function leadMagnetSettingFields(): array
{
    return app(SettingsRegistry::class)->fields('lead-magnets');
}

/**
 * Das Formular schickt immer den ganzen Abschnitt, deshalb sind die Regeln
 * `present` und eine halbe Nutzlast ist ein 422 statt eines halben Schreibens.
 * Ein Test, dem ein Schluessel wichtig ist, nennt den; den Rest fuellt das hier
 * aus der Config.
 */
function patchLeadMagnetSettings($test, array $overrides)
{
    $settings = [];

    foreach (array_keys(leadMagnetSettingFields()) as $key) {
        $settings[$key] = config('lead-magnets.'.$key);
    }

    return $test->patchJson(cp_route('brand-context.settings.update'), [
        'namespace' => 'lead-magnets',
        'settings' => array_replace($settings, $overrides),
    ]);
}

function leadMagnetStoredSetting(string $key): ?BrandSetting
{
    return BrandSetting::query()->where('namespace', 'lead-magnets')->where('key', $key)->first();
}

it('registers lead magnets with the suite settings registry', function (): void {
    // Das Einzige, was keine andere Behauptung hier faengt. Alles Weitere
    // laeuft gegen den Manager, dem egal ist, wer register() gerufen hat.
    $registry = app(SettingsRegistry::class);

    expect($registry->has('lead-magnets'))->toBeTrue()
        ->and($registry->provider('lead-magnets'))->toBe(Settings::class)
        ->and($registry->configPath('lead-magnets'))->toBe('lead-magnets')
        ->and($registry->permission('lead-magnets'))->toBe('manage lead-magnets settings');
});

it('offers no key that is read while the application boots', function (): void {
    // Der Fehler, an dem so eine Seite still scheitert. `SettingsManager::apply()`
    // laeuft aus `app->booted()`; was beim Registrieren der Routen gelesen wird,
    // sieht dort noch den Paketwert. Ein Schalter, der erst beim naechsten
    // Deploy wirkt, ist eine Luege in der Oberflaeche.
    $keys = array_keys(leadMagnetSettingFields());

    expect($keys)->not->toContain('routes.prefix')      // routes/web.php:22
        ->and($keys)->not->toContain('requests.throttle') // routes/web.php:30
        ->and($keys)->not->toContain('assets.disk')       // ServiceProvider::bootAssetDisk()
        ->and($keys)->not->toContain('assets.container')
        ->and($keys)->not->toContain('delivery.disk')
        // Install-Zeit-Werte: beide stehen im eindeutigen Schluessel von
        // entitlements, eine Aenderung verwaist jede bestehende Freigabe.
        ->and($keys)->not->toContain('entitlements.source')
        ->and($keys)->not->toContain('entitlements.subject_type');
});

it('carries a changed link lifetime through to the resource that signs the link', function (): void {
    patchLeadMagnetSettings($this, ['delivery.link_ttl' => 45])->assertRedirect();

    $resource = makeResource(['link_ttl' => null]);

    // Nicht `config(…)`, sondern der Leser: das Modell entscheidet, wie lange
    // ein verschickter Link gilt.
    expect(leadMagnetStoredSetting('delivery.link_ttl')?->value)->toBe(45)
        ->and($resource->linkTtlMinutes())->toBe(45);
});

it('keeps an empty download cap apart from a cap of one', function (): void {
    // `nullable` ist hier kein Beiwerk: leer heisst „kein Deckel", und das ist
    // ein anderer Zustand als eine Zahl.
    patchLeadMagnetSettings($this, ['delivery.max_downloads' => 3])->assertRedirect();
    expect(makeResource(['handle' => 'capped'])->maxDownloads())->toBe(3);

    patchLeadMagnetSettings($this, ['delivery.max_downloads' => null])->assertRedirect();
    expect(makeResource(['handle' => 'uncapped'])->maxDownloads())->toBeNull();
});

it('lets the public request form fall into a renamed honeypot', function (): void {
    // Die Grenze, auf die es ankommt: der Wert wird im Controller gelesen, und
    // ein Bot, der das umbenannte Feld ausfuellt, bekommt einen glaubhaften
    // Erfolg und sonst nichts.
    patchLeadMagnetSettings($this, ['requests.honeypot' => 'gotcha'])->assertRedirect();

    $resource = makeResource();

    $this->post(route('lead-magnets.request'), [
        'resource' => $resource->handle,
        'email' => 'bot@example.com',
        'gotcha' => 'ich bin ein bot',
    ]);

    expect(Grant::query()->count())->toBe(0);

    // Und die Gegenprobe, ohne die die Behauptung auch fuer ein kaputtes
    // Formular gaelte: dieselbe Anfrage ohne das Fallenfeld legt einen Zugang an.
    $this->post(route('lead-magnets.request'), [
        'resource' => $resource->handle,
        'email' => 'mensch@example.com',
    ]);

    expect(Grant::query()->count())->toBe(1);
});

it('switches an installed sibling off from the screen', function (): void {
    SiblingStubs::bindAll();
    app()->forgetInstance(SiblingBridges::class);
    app(SiblingBridges::class)->boot(app('events'));

    expect(app(LeadhubBridge::class)->available())->toBeTrue();

    patchLeadMagnetSettings($this, ['integrations.leadhub' => false])->assertRedirect();

    // Die Bruecke fragt bei jedem Aufruf neu, nicht nur beim Booten — sonst
    // waere der Schalter erst nach dem naechsten Deploy wahr.
    expect(app(LeadhubBridge::class)->available())->toBeFalse();

    $resource = makeResource(['requires_confirmation' => false, 'tags' => ['lead-magnet']]);

    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');

    expect(FakeLeadHubFacade::$contacts)->not->toHaveKey('reader@example.com');
});

it('refuses a confirmation window of zero hours', function (): void {
    $response = patchLeadMagnetSettings($this, ['requests.confirmation_ttl_hours' => 0])->assertStatus(422);

    expect($response->json('errors'))->toHaveKey('settings.requests.confirmation_ttl_hours')
        ->and(config('lead-magnets.requests.confirmation_ttl_hours'))->toBe(72);
});

it('deletes the override when a value goes back to the packaged default', function (): void {
    patchLeadMagnetSettings($this, ['delivery.link_ttl' => 45])->assertRedirect();
    expect(leadMagnetStoredSetting('delivery.link_ttl'))->not->toBeNull();

    // Keine Zeile, die den Standard festnagelt — die wuerde ihn ueber jedes
    // Paket-Update hinweg einfrieren.
    patchLeadMagnetSettings($this, ['delivery.link_ttl' => 60 * 24 * 7])->assertRedirect();

    expect(leadMagnetStoredSetting('delivery.link_ttl'))->toBeNull()
        ->and(makeResource(['handle' => 'back_to_default'])->linkTtlMinutes())->toBe(60 * 24 * 7);
});

it('offers no credential field', function (): void {
    foreach (array_keys(leadMagnetSettingFields()) as $key) {
        foreach (['token', 'secret', 'api_key', 'password'] as $needle) {
            expect(str_contains(strtolower($key), $needle))->toBeFalse(
                "Settings offers a credential field: {$key}"
            );
        }
    }
});
