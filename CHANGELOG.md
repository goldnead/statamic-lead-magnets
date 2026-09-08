# Changelog

## 3.5.0 — 2026-09-07

### The detail page is the form

Adrian on 2026-09-03, finding F08: the "Edit" button led to a second form on a page of its own,
while the detail page showed only pills. With a collection entry there is no such break. There
is none here any more either: the detail page carries the fields, "Save" sits at the top right,
delete sits in the "…" menu beside it, and below them stand the grants as before. The route
`lead-magnets.resources.edit` is gone; "Create resource" stays a page of its own, because there
is no record there yet.

Anyone who may only view the resources still gets the page — with locked fields, without the
file picker and without a save or delete address.

### No more content directly on grey ground

Finding F06. Two grey containers with a heading and white islands inside them: the nesting was
the reverse of the Statamic norm, and on the grants the tab, search field, filter button and
column headers sat on grey. Now the grey ground carries the page, the fields sit on white cards,
and the grant listing renders like every core listing — with a white table frame of its own.

### Settings in the Control Panel

Delivery deadlines, confirmation window, honeypot field, mail templates and the six sibling
switches sit under `/cp/brand-settings` in the "Lead Magnets" section, provided by
`goldnead/statamic-brand-context` (now `^1.13`). New permission: `manage lead-magnets settings`;
nobody holds it at first, and until it is assigned to a role the section stays invisible. The
existing permissions are unchanged.

**Why the line is drawn at 1.13 and not at 1.12.** Older versions carry the page but do not apply
its values reliably: on an installation with a single brand the settings of the addons registered
last were not laid onto the config at all, and up to 1.12 a second save of the same section
deleted the first save's override without a message. If you set values before this update, check
afterwards whether they are still there.

Not on the page, and that is deliberate: `routes.prefix` and `requests.throttle` are read while
the routes are registered, `assets.disk`, `assets.container` and `delivery.disk` at boot and
before a move of the files respectively, and `entitlements.source` and
`entitlements.subject_type` are install-time values whose change orphans every existing grant. A
switch that only takes effect at the next deploy would be a lie in the interface.

## 3.4.0 — 2026-09-07

### Upload a file instead of typing a path — in a container of its own, still through the signed route

Adrian on 2026-09-03: "There is a Statamic Asset Manager, couldn't we actually use that?" Until
now a file resource carried two text fields, "File path" and "Disk", and somebody else had to
have put the file on the disk. Now Statamic's own `assets` fieldtype stands there: browse,
upload, replace.

**This is not just a field.** A file in an asset container raises the question whether it now lies
openly on the web, and a lead magnet behind double opt-in is meant to do exactly the opposite.
The fieldtype would have taken the container the site already has by itself — with Statamic as a
rule `public/assets`, with a URL and public visibility. That would have been the download without
confirmation, and nothing in the Control Panel would have said so.

That is why the addon creates a **container of its own** (`lead_magnets`), on a **disk of its
own** (`lead-magnets`), which it defines itself: `storage/app/lead-magnets`, without `url`,
without `serve`, without public visibility. That puts it outside the document root, Laravel puts
no route on it, and the signed download route stays the only way to the file. The container comes
into being when the form is first opened, not through a command nobody runs.

Shown rather than claimed: a test fetches the file through every public address it could have and
is refused every time — and in the same test serves the same bytes through the signed route. A
second test shows that the warning bites: on Laravel's `public` disk the form says so in red.

`AssetContainer::private()` is not enough for this. It reads only the `url` key and knows neither
public visibility nor a root below `public/`. The addon asks the further question itself.

**The link path is unchanged.** Both paths stand side by side, and the listing now shows, beside
the pill, what it delivers: the file name or the target URL. A record can carry something in both
columns; the download route decides by `delivery_type` alone, and exactly that source stands
beside it.

What is stored is still a path on a disk — delivery is untouched. The fieldtype speaks asset IDs;
the repacking happens at this one seam. An ID from a foreign container is refused, and an empty
selection while editing does not delete a stored file.

Gone: the input fields "File path" and "Disk". The disk now comes from the container and no
longer from the form.

## 3.3.1 — 2026-09-03

### Fixed: error banners as `Alert`, delete moved into the header menu

Three error banners were a red `div` on a bare grey panel — the Control Panel's banner component
is `Alert`. The delete button carried `variant="danger"`, which core uses only in the confirm
dialog; it now sits in the `…` menu.

The icon `refresh` does not exist, now `sync`. An unknown name renders an empty box and says
nothing about it.

## 3.3.0 — 2026-09-02

### Fixed — supplied values landed raw in the mail's HTML

`EmailTemplatesBridge` inserted the variables into a CP template with
`str_replace`, without escaping them. Both of this addon's mails go to an
address that has only just been entered and confirmed by nobody yet, and
`{{ email }}` is exactly what the visitor typed into the form. The same class of
bug as in `statamic-payments` (`AbandonedReminder`, fixed on the same day), and
the same solution:

- Values are escaped with `e()` when inserted into the HTML body.
- **`EmailTemplatesBridge::RAW_VARIABLES`** names the exceptions: `confirm_url`
  and `download_url`. Both are links this addon builds itself, both stand in an
  `href`, and both carry a query string whose `&` must stay intact.
- The **subject line** is not HTML and is filled with `escape: false`; an
  `&amp;` in the subject would be visible damage rather than protection.

The template itself stays untouched — what an editor writes as HTML in the CP is
still HTML. Only what is inserted from outside is escaped.

## 3.2.0 — 2026-08-29

### Added: this addon's figures appear in Insights

From 1.1.0 `statamic-insights` is no longer a revenue report but the family's reporting layer: an
addon registers what it can count and gets the period, the comparison against the period before,
the chart, the breakdowns and two finished screens in return.

The coupling is optional in **both** directions. Without Insights nothing here is missing; without
this addon only its own group is missing over there. `suggest`, never `require`.

Every figure follows the contract's house rules: **null is not zero** (a rate with no denominator
has no answer and does not print 0 %), `available()` decides existence and never the data, gaps in
a series are filled by Insights rather than by the metric, and a filter a metric does not
understand is ignored rather than fatal.

Four figures: requested, confirmed, downloaded, confirmation rate.

The rate measures a **cohort**: of the requests in this period, the share that confirmed, counted
on the day of the request — even when the confirmation came later. Numerator and denominator sit
in different time zones on disk, because the confirmation lives in the sibling addon's table. Each
side therefore names its own zone and shares the rest of the window; otherwise the two halves of a
rate drift apart.

### Fixed: a figure counts the current brand only

While the integration was being built this question got four different answers within the family,
and side by side on one screen that is worse than none: one tile showed three other brands'
turnover while its neighbour filtered correctly. The rule now lives once, in
`TableMetric::brandScoped()`, transcribed from `BrandScope::apply()`; here only the column is
named, and the figure, the chart and every breakdown narrow together.

With no brand selected the tile reads **0 and stays**. A reader can make sense of a zero; a tile
that is not there he cannot notice.

## 3.1.0 — 2026-08-24

### Fixed — both mails went out under the host's identity

`DeliveryService` called `Mail::to()`, that is, the process-wide default mailer.
On a host with several brands that means: brand A's confirmation and delivery go
out through brand B's relay. The relay refuses, because the domain is not
verified there — or it goes through, and the reader gets mail from a sender he
has never heard of.

That weighs more here than elsewhere: **both mails go to somebody from the
public who has just handed over his address.**

Both paths now go through `Sending\BrandMailer`, the same door as in marketing,
notifications, preference-center, automations, leadhub and webhook-manager. The
contract is in `statamic-brand-context` ^1.8.

**For single-brand installations nothing changes.**

**New:** if the brand identity is refused, nothing is sent and the reason lands
on the grant (`delivery_sender_refused` / `confirmation_sender_refused`). "The
mail never arrived" thereby has a cause instead of being a riddle.


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
