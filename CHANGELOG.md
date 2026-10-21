# Changelog

## Unreleased

## 3.18.0 — 2026-10-21

### Added: the mails of a lead magnet in the Probelauf

The confirmation and the delivery mail of a lead magnet have a preview in the dry run of the map (`mailPreview()` on the map provider;
the mail effects name their kind in `meta.kind`). `DeliveryService::previewMail()` renders them like the real mail: the template the editor
wrote, else the addon's own view; the disclosure of a coupled list, the file list and the placeholders listeners add are there. The address
in the mail is `#`: no grant is stored, no link is signed, nothing is sent, and no suppression check or event of a send runs.

## 3.17.0 — 2026-10-21

### Changed: the access line of the dry run names the access

The dry run's access line of an access lead magnet carries the product slug it grants (`meta.slug`), so the steps after it can ask whether
the person holds it and the funnels map can list it in the person's account.

## 3.16.0 — 2026-10-21

### Added: dry run of a lead magnet (map)

- A lead magnet can be simulated on the funnels map: a request starts the path, and the answer lists what it would do,
  the confirmation mail and wait, the delivery mail, the access and the course it unlocks. Nothing is sent, queued or
  granted. A known person with the access only gets the delivery mail again. Unpublished or missing magnets stop or
  answer unknown. Only users with `manage lead magnets` may open it (`simulationAuthorized()`). The delivery mail
  subject is the standard one; a subject set in an e-mail template is not shown. For an access magnet the access is
  granted before the mail that links to it, and a mail series that is switched on and that the person has not been
  through takes the delivery over (no delivery mail). A revoked or scheduled access, which makes the real request do
  nothing, is not modelled.

## 3.15.0 — 2026-10-21

### Added: funnels map detail panel

- A lead magnet card opens with the delivery type, the requests in the period (the same count the card
  shows, now one method for both) and the target: the course an access product opens, or the file name(s).
  A row without a source is left out. Read only. For an access lead magnet the target is the product
  (one lookup, the slug as text if it is unknown); the course behind it is a connection in the panel,
  and the panel computes no edges. Only users with `manage lead magnets` may open it
  (`detailsAuthorized()`). The panel needs statamic-brand-context 1.20 (permission check); older
  versions leave it empty.

## 3.14.0 — 2026-10-20

### Added: draw lead magnet -> course on the map (connections)

- The map provider offers the pair lead magnet -> course (`unlocks`). Connecting writes the course's
  product slug into the lead magnet's *Access product*; disconnecting clears it, only when it holds
  exactly that slug. A lead magnet that is not of type access, a field already holding another product
  and a course without a product slug are refused with a reason; nothing is ever overwritten.
- Gated by `manage lead magnets`, the permission that guards editing a lead magnet. Duck-typed: no new
  brand-context class is referenced, so the addon keeps working with brand-context ^1.18.

### Added: the course an access lead magnet opens (map)

- A lead magnet of type access draws an edge "unlocks" to the course its *Access product* opens. The
  edge is computed from the field `access_product` and the courses addon's own product -> course edge,
  asked through the map registry, so no dependency on `goldnead/statamic-courses` is added. A product
  no course opens (a membership, or an installation without courses) draws no edge and raises no
  problem. Nothing is stored.

## 3.13.0 — 2026-10-19

### Added: the lead magnet's page (map)

- A resource has a *Page* field: the entry id of the page its sign-up form stands on. The funnels map
  draws one edge from that page to the lead magnet, labelled "collects sign-ups". An empty field draws
  no edge. The edge needs `goldnead/statamic-brand-context` 1.18 or newer.

### Upgrading: run php artisan migrate (one nullable column)

- One migration: `page_entry` on `lead_magnet_resources`, nullable. Existing resources do not change.

## 3.12.0 — 2026-10-19

### Added

- Delivery method *Access*: a confirmed reader is granted a product (a course, material) on their
  account instead of getting a file. The host binds `Contracts\AccessSubjectResolver` to find or create
  the account and to give a sign-in link; the addon creates no users. Fields: product slug,
  landing path. New placeholder `{{ access_url }}` in the delivery template.
- *Mail series* on a resource: the handle of an automation whose first mail replaces the delivery
  mail for a first-time reader. Falls back to the delivery mail without the automations addon, with
  the automation off, or for a reader who had the series (optional bridge, reads automations'
  repository and run history). The series itself starts from its own trigger, for example
  `entitlements.granted` on the product.
- Event `DeliveryVariables`: add placeholders to the confirmation and delivery mails from a
  listener, no subclass of `DeliveryService` needed.
- A request notice: `notifications.webhook_url` posts every request to a Discord-compatible webhook.
- A signed download link of an access resource answers 404.

### Upgrading

- One migration (`access_product`, `access_path`, `delivery_series` on `lead_magnet_resources`, all
  nullable). Existing resources do not change.
- Optional: `notifications.*` and `entitlements.access_source` in the config.

## 3.11.0 — 2026-10-18

### Added

- The address in the resource's *Access* table links to the LeadHub contact when
  goldnead/statamic-leadhub is installed and the user may view contacts. No hard dependency; without
  LeadHub the cell stays text. If the address now resolves to a different contact than the one the
  access was synced to, there is no link and the row says so.
- Funnels map: each lead magnet is offered to the map as the object type `lead_magnet`. The card shows the title, a link to the resource in the Control Panel, whether the resource is published, its delivery (file or link), and the number of requests over the period. The id is the resource handle. Needs statamic-brand-context 1.17 or newer.

### Upgrading

- Requires `goldnead/statamic-brand-context` `^1.17` (was `^1.13`). No migration, no new config key.

## 3.10.0 — 2026-10-17

### Changed

- Settings: the addon now declares `settingsNavParent()` (`[section, name]` of its own Control Panel navigation entry). With statamic-brand-context 1.16 or newer its settings show as the sub-item "Einstellungen" under that entry; addons without a navigation of their own share one "Addon-Suite" page under Settings. Older brand-context versions ignore the method and keep the previous layout.

### Upgrading

- No migration, no new config key.

## 3.9.1 — 2026-10-11

### A template with a leftover `{{ … }}` is no longer sent

Only plain `{{ variable }}` placeholders are filled in. A template that kept an Antlers
`{{ if }}` went to the relay as it was; Brevo silently dropped such a mail on 10.10.2026, and the
grant was still marked delivered.

- After the variables are filled in, a `{{ … }}` left in the subject or the body stops the send
  (delivery mail and confirmation mail alike). Nothing goes to the relay and `delivered_at` stays
  empty.
- The error log names the resource handle, the template slug and the leftover snippet. The grant
  carries `last_hold` = `delivery_template_unresolved` / `confirmation_template_unresolved`.
- Values that happen to contain braces are not mistaken for a leftover. Templates without a leftover
  render exactly as before.
- README: delivery templates support plain variables only.

## 3.9.0 — 2026-10-10

### Return URL for the confirmation link

`LeadMagnets::request()` accepts `return_url` in its meta: a URL signed by the application, on the
requesting host. After the reader confirmed, the confirmation link (and the consent button) redirects
there with a 303 instead of showing the addon's page. This is how another addon, such as
statamic-funnels, picks the reader up again at its next step.

- Not an open redirect: the value is never read from the public form, anything unsigned or on
  another host is dropped with a log warning, and the signature and host are checked again when the
  link is followed.
- A lapsed confirmation or a revoked grant does not redirect.
- A repeat request replaces the return URL; one without a return URL clears the old one.
- New class `Support\ReturnUrl`. No migration: the URL is kept in the grant's `meta`.

## 3.8.1 — 2026-10-10

### Fixed: open redirect on the request form

The request endpoint followed whatever the hidden `_redirect` field held, so a link or form on
the real domain could send a reader to any other site after the opt-in (phishing under the
brand). `_redirect` is now followed only when it is a path on this site (one leading `/`) or an
absolute http(s) URL with this request's own scheme, host and port. Protocol-relative URLs
(`//host`), backslash and whitespace tricks, `javascript:`/`data:` schemes, credentials in the URL
and non-string values are refused; the reader then lands on the previous page as if no
`_redirect` had been sent. Relative paths such as `/thanks` keep working. Sites that put a
relative path without a leading slash (`thanks`) or another host in `_redirect` must change it.

## 3.8.0 — 2026-10-07

### One confirmation for the file and the mailing list

A resource can couple its mailing list to its own confirmation (*Subscribe through this
confirmation*). The reader confirms once: the file is delivered and the address is subscribed, and
marketing sends no second confirmation for that subscription. Off by default; every existing
resource behaves as before.

- Takes effect only with double opt-in on, a mailing list named and a **disclosure** sentence
  (`list_consent_text`). The CP refuses the switch without all three.
- The disclosure is in the confirmation mail (Blade view and the new template variable
  `{{ list_consent_text }}`) and on the confirmation page. The grant keeps a copy taken when the
  confirmation went out (`meta.list_consent`), so the consent record names what the reader was
  shown even if the sentence changes later.
- **Opening the link is not consent.** For a coupled grant `GET /confirm/{token}` shows the
  disclosure and a button and changes nothing; the new `POST /confirm/{token}`
  (`lead-magnets.confirm.store`) confirms. Uncoupled resources keep confirming on GET.
- The subscription goes through marketing's public `subscribe()` with `skip_confirmation` and
  `meta.consent`: method, list, wording, source (`lead-magnets:<handle>`), `requested_at`,
  `confirmed_at`. An editor reinstating a pending grant does not count as the reader's consent:
  the list then asks for its own confirmation, as before. Neither does a press whose activation
  failed or lost the race (held in memory for the one activation, `ListConsentPress`), nor an old
  copy of the disclosure on a grant asked for again (every request drops it).
- Switching coupling off stops confirmation mails already sent from subscribing into the list.
- A confirmation template that leaves the disclosure out, and a confirmed subscription that cannot
  be recorded (marketing missing, list missing), are named in the log.
- Unsubscribing from the list does not touch the grant; the file stays downloadable.

### Mail templates per resource

`confirmation_template` and `delivery_template` on a resource name an email-templates slug for
that resource's mails; empty falls back to `lead-magnets.mail.*_template`.

- New migration `add_list_consent_and_templates_to_lead_magnet_resources_table`. Run
  `php artisan migrate`.
- Published `mail/confirmation*.blade.php` views do not show the disclosure; republish them or
  add it.

## 3.7.0 — 2026-10-04

### Several files per resource

A resource carries an ordered list of files, each with a label and a group. The delivery mail lists all
of them grouped, every file with its own signed, expiring link, and the plain download link opens an
overview page with the same list. The Control Panel edits the list with core's grid and asset fields,
laid out like the downloads of a statamic-courses material: a group once, its files underneath
("Add group" / "Add file"), each file picked and optionally labelled, drag to reorder.

- Download names are `Title - Group - Label.ext`, with a German-spelled ASCII fallback (`Übe` → `Uebe`).
- In the mail and on the page every file is its own 44px-high row.
- **Republish published mail views.** A delivery view published into your site, or an email-templates
  template written before the list, does not list the files. Republish the views or add `{{ file_list }}`;
  the log carries a warning when a multi-file delivery goes out through such a template.
- The stored list stays flat; a list saved while this was in development reads as group blocks.

- Existing single-file resources are unchanged and need no data migration: a resource without a list is
  read as a list of one, keeps its direct link and its file name.
- New migration `add_files_to_lead_magnet_resources_table`: `files` (JSON, null on every existing row)
  and `file_key` on downloads. Run `php artisan migrate`.
- New signed route `lead-magnets.download.file` (`/download/{grant}/{file}`). The file key is part of
  the signature and stays stable across reordering.
- With a list, `max_downloads` counts per file instead of per grant.
- Email-templates: the new variable `{{ file_list }}` (grouped list as HTML).
- The form still accepts the old `file_asset` field as a list of one.

### Fixed

- The plain-text part of the confirmation and delivery mails HTML-escaped the signed URL, so `&` became
  `&amp;` and the link in the text part answered 403. Text views now write the URL as is.

## 3.6.1 — 2026-09-25

### Fixed

- `resources/lang/de.json` no longer translates Statamic's "Disk" globally ("Speicherort" also showed in Statamic's asset container settings). The key was not used by this addon.

## 3.6.0 — 2026-09-09

### A missing table is a sentence, not a stack trace

The nav entry appears the moment the addon is installed, so the resources screen was reachable
before anybody had run `php artisan migrate`. It then queried a table that did not exist and
answered HTTP 500. Now the listing and the detail page check first, and a site that is not
migrated yet gets a setup screen naming the tables that are missing and the command that creates
them.

The check names foreign tables too. The two counts on the listing join into `entitlements`, which
belongs to a sibling addon, so a half-migrated install would crash there just as readily — and
that would be the more confusing of the two failures. Every guarded page that turns somebody away
writes the reason to the log first: a page that renders an empty state and stays silent everywhere
would be worse than the crash it replaced, because the site would look installed and never work.

### Settings no longer freeze on a fresh install

The addon's own config is merged in `register()` now, not during boot. `brand-context` (from
1.13.0) keeps the packaged values as its baseline the first time settings are applied, out of
`app->booted()`; Statamic calls `bootAddon()` from a later `booted()` callback of its own. While
the merge hung there, `config('lead-magnets')` was still empty when the baseline was taken, and
`??=` froze that emptiness for the rest of the process.

The cost was silent: `packagedDefault()` answered `null` for every key, no stored value ever
matched its packaged default, the row in `brand_settings` was never deleted, and every setting
stayed nailed to its value — the installation frozen against future package updates, with no error
and no message. Publishing stays in the boot phase, as does the asset disk.

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
