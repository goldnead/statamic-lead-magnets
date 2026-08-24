# Changelog

## 2.1.0 — 2026-08-24

### Fixed — beide Mails gingen unter der Identität des Hosts raus

`DeliveryService` rief `Mail::to()`, also den prozessweiten Vorgabe-Mailer.
Auf einem Host mit mehreren Marken heißt das: die Bestätigung und die
Auslieferung von Marke A gehen über das Relay von Marke B. Das Relay lehnt ab,
weil die Domain dort nicht verifiziert ist — oder es geht durch, und der Leser
bekommt Post von einem Absender, von dem er nie gehört hat.

Das wiegt hier schwerer als anderswo: **beide Mails gehen an jemanden aus der
Öffentlichkeit, der gerade seine Adresse hergegeben hat.**

Beide Wege gehen jetzt durch `Sending\BrandMailer`, dieselbe Tür wie in
marketing, notifications, preference-center, automations, leadhub und
webhook-manager. Der Vertrag steht in `statamic-brand-context` ^1.8.

**Für Ein-Marken-Installationen ändert sich nichts.**

**Neu:** verweigert die Marken-Identität, wird nicht gesendet und der Grund
landet am Grant (`delivery_sender_refused` / `confirmation_sender_refused`).
„Die Mail kam nie an" hat damit eine Ursache statt ein Rätsel zu sein.


All notable changes to `goldnead/statamic-lead-magnets` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [3.0.0] — 2026-08-09

### Changed — the licence is now proprietary

This is a paid Marketplace addon. `composer.json` declares `proprietary` and the
licence file carries the commercial addon licence instead of MIT. Entitlement is
enforced by the Statamic Marketplace, not by code in this package.

Tags up to and including `v2.0.0` remain MIT. The change takes effect with the next
release.

## [2.0.0] — 2026-08-04

Grant state moves out of this package and into
`goldnead/statamic-entitlements`.

**This is a major version, and the reason is the public contract, not the
internals.** `Goldnead\LeadMagnets\GrantState` was a public class; `$grant->state`
was a public string property that host code, the Control Panel payload and the
event payloads all read; `LeadMagnets::revoke()` took one argument. All three
change. A minor release cannot carry a removed class, a removed column and a new
required argument, whatever the convenience of pretending otherwise. There is
also a mandatory data migration, and a version number that did not say so would
be lying about what an upgrade costs.

### Upgrading

Two steps, in this order. The second schema migration **aborts on purpose**
while any grant still has no entitlement, so that state cannot be dropped before
it is carried across:

```bash
php artisan migrate                              # adds the new columns, then aborts
php artisan lead-magnets:migrate-grants --dry-run
php artisan lead-magnets:migrate-grants
php artisan migrate                              # drops the legacy columns
```

The command is idempotent, brand-aware and sends no mail. A fresh install sees
none of it.

Code changes on the consumer side:

| 1.x | 2.0 |
|---|---|
| `use Goldnead\LeadMagnets\GrantState;` | `use Goldnead\Entitlements\Enums\EntitlementState;` |
| `$grant->state` (string) | `$grant->state()` (enum) or `$grant->stateValue()` |
| `$grant->confirmed_at` | `$grant->confirmedAt()` |
| `$grant->revoked_at` | `$grant->revokedAt()` |
| `$grant->expires_at` | `$grant->accessEndsAt()` |
| `LeadMagnets::revoke($grant)` | `LeadMagnets::revoke($grant, 'why')` |

`request()`, `confirm()`, `findGrant()`, `downloadUrl()` and `reinstate()` are
unchanged, as are the four domain events, the three public routes, the two
permissions and every configuration key that existed in 1.x.

### Changed

- **`goldnead/statamic-entitlements` is a hard Composer requirement.** The
  optional siblings stay optional and the suite still runs with none of them
  installed; entitlements is not one of them. Access state is not something this
  addon can half-have.
- Access state is resolved by entitlements' `StateResolver` and nowhere else.
  Six states arrive with it; this addon writes three (`pending`, `active`,
  `revoked`) and reads all six. `expired` is derived from the clock and written
  by nobody, `scheduled` and `grace_period` have no writer here but are honoured
  on the download gate — a grant inside its grace period serves, a grant that has
  not started does not.
- Revocation now requires a reason, records it, and sets `revoked_at`. In 1.x
  the column existed, was displayed and was never set by anything.
- `lead-magnets:sweep` no longer marks grants expired — nothing needs to. It
  clears confirmation tokens whose window has closed, and the hourly schedule
  entry stays.
- The delivery mail is triggered by `EntitlementGranted` rather than by a direct
  call, and only for a transition out of `pending`. Entitlements sends nothing
  itself, by design.
- The Control Panel grant filter offers all six states and the listing renders
  them.
- A repeat request after an access window closed opens a **second** entitlement
  rather than rewriting the first, so an expired period stays on the record.

### Added

- `lead-magnets:migrate-grants`, with `--dry-run` and `--brand=`, carrying 1.x
  grant state into entitlements. Idempotent: a second run changes nothing.
- `lead_magnet_grants.entitlement_id`, `attempt` and `confirm_expires_at`.
- `config('lead-magnets.entitlements.source')` and
  `config('lead-magnets.entitlements.subject_type')`.
- `Goldnead\LeadMagnets\Support\LeadMagnetSubject`, which turns an address into
  the entitlements subject reference so a host application can ask
  `Entitlements::allows()` about it directly.
- `LeadMagnets::entitlementFor($grant)`.

### Removed

- `Goldnead\LeadMagnets\GrantState`.
- The `state`, `confirmed_at`, `revoked_at` and `expires_at` columns on
  `lead_magnet_grants`, and the model accessors over them.
- `GrantService::sweepExpired()`, replaced by `sweepExpiredTokens()`.

### Fixed

- The confirmation deadline can no longer become the access expiry. In 1.0.0 the
  two shared one column and activation had to overwrite one with the other;
  without that single line every confirmed access would have expired silently 72
  hours later and surfaced weeks on as "the download link stopped working". They
  are now two columns on two different rows, so there is nothing to overwrite and
  no overwrite to forget. `tests/Feature/ActivationWindowTest.php` asserts the
  behaviour rather than the arrangement.
- Activation no longer decides the winner of a confirmation race from an
  affected-row count that MySQL and SQLite disagree about. MySQL reports zero
  changed rows when an UPDATE writes a value a column already holds, which is the
  common case here (`NULL` over `NULL` for a resource with no lifetime); the
  winner is decided by the status change instead.
- Deleting a resource now removes the entitlements this addon wrote for it,
  including those of earlier access periods. They would otherwise sit in the
  shared listing as access to a product nothing answers to.

---

## [1.0.0] — 2026-08-02

### Added

- Gated resources in the Control Panel: file or link, with double opt-in
  switchable per resource.
- Public request endpoint with honeypot, throttle and address normalisation.
- Confirm-first grant state (`pending` → `active`), activated by a conditional
  UPDATE so a repeated confirmation activates and delivers exactly once.
- Signed, time-boxed download links, capped by the grant's own lifetime and by
  an optional download limit.
- Download audit: one row per redemption, with a hashed client address.
- Domain events `ResourceRequested`, `ResourceConfirmed`, `ResourceDelivered`
  and `ResourceDownloaded`.
- Optional bridges to leadhub (contact and tags), marketing (mailing-list
  subscription), email-templates (mail bodies), suppression (send gate) and
  activity (ledger). Each is inert when its addon is absent.
- `lead-magnets:sweep` console command and an hourly schedule entry for
  housekeeping of lapsed grants.
- Multi-brand support through `goldnead/statamic-brand-context`: resources,
  grants and download rows are brand-scoped, and each session-less public route
  derives the brand from the value the visitor already carries. Resource handles
  are unique across all brands, which is what makes that derivation safe.

### Notes

- The Control Panel bundle is not committed. It is attached to each GitHub
  release by `.github/workflows/release-dist.yml` and fetched at install time
  by `pixelfear/composer-dist-plugin` (`extra.download-dist`). A tag published
  without that workflow succeeding installs with no CP assets.
- Grant state lives in this package rather than in
  `goldnead/statamic-entitlements`, which does not exist yet. The reasoning and
  the cost are in the README under "Grant state".
