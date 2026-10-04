# Lead Magnets for Statamic 6

Gated resources with confirm-first delivery. A visitor asks for a file, confirms
the address, and gets a signed download link that expires, can be capped, can be
revoked, and leaves an audit row every time it is used.

Statamic 6 only. Laravel 12.40+ / 13.

---

## What it does

- **Resources** in the Control Panel — a file on a disk or a link, with a title,
  description, publish state and per-resource delivery settings.
- **A request endpoint** for your own form, with a honeypot and a throttle.
- **Optional double opt-in**, switchable per resource.
- **Access state from `goldnead/statamic-entitlements`** — one state machine for
  the whole platform, one grant per address per resource per access period.
- **Signed download links** — time-boxed, optionally limited by download count,
  and never longer-lived than the access they belong to.
- **A download audit** — who, when, how often, from which request.
- **Tags on the contact** when access activates, if Leadhub is installed.
- **Domain events** — `ResourceRequested`, `ResourceConfirmed`,
  `ResourceDelivered`, `ResourceDownloaded`.

## What it does not do

Account-based access instead of a download (that needs identity decisions this
package does not make), follow-up sequences (they belong in
`goldnead/statamic-marketing`), segments, and analytics conversion events.

---

## Requirements

| | |
|---|---|
| PHP | 8.2+ |
| Laravel | 12.40+ or 13 |
| Statamic | 6.0+ |
| Hard dependencies | `goldnead/statamic-brand-context`, `goldnead/statamic-entitlements` |

Everything else is optional. **The addon is fully functional with no *optional*
sibling installed**: it sends its own confirmation mail and serves its own
downloads. The test suite runs with none of them present, which is what makes
that a claim rather than a hope.

Entitlements is the exception and it is a hard requirement, not a bridge. Access
state is not something this addon can half-have — an install where it was absent
would have no way to answer "may this person download this file".

| Optional addon | What it adds |
|---|---|
| `goldnead/statamic-leadhub` | Creates the contact and writes the resource's tags onto it |
| `goldnead/statamic-marketing` | Subscribes the confirmed address to a named mailing list |
| `goldnead/statamic-email-templates` | Lets an editor author the two mails in the CP |
| `goldnead/statamic-suppression` | Blocks delivery to bounced or complaining addresses |
| `goldnead/statamic-activity` | Records all four events on the shared ledger |

---

## Installation

```bash
composer require goldnead/statamic-lead-magnets
php artisan migrate
```

Optionally publish the config and the public views:

```bash
php artisan vendor:publish --tag=lead-magnets-config
php artisan vendor:publish --tag=lead-magnets-views
```

Grant the `view lead magnets` permission to the roles that need the CP screen.

---

## Usage

### 1. Create a resource

**Tools → Lead Magnets → Create resource.** Give it a title, pick *File* or
*Link*, and set the handle — the handle is what your form names, and it must be
unique across every brand (see *Multi-brand* below).

*File* gives you Statamic's asset browser, over a container of this addon's own
that is created the first time you open the form. Upload there, or pick a file
already in it. The container is **not** one of the site's existing ones and that
is deliberate — see *Where the files live* below.

#### Several files in one resource

The *Files* field is built like the downloads of a statamic-courses material: a
**group** once (for example a voicing), its files underneath, each file picked
with the asset browser and given an optional **label**. *Add group* and *Add
file* append; drag to reorder, groups and files alike. Nothing is typed per
file and nothing from memory: the group name is written once, so a typo cannot
open a tenth group. A group left empty lists its files without a heading.
Internally the list stays flat (`files`, each entry with its group), and a list
saved by the first release reads as blocks, in order of each group's first file.

Delivery then looks like this:

- **The delivery mail lists every file**, grouped, each with its own signed,
  expiring link, plus one link to a page that shows the same list. Groups appear
  in the order of their first row; files keep the list order inside a group;
  files without a group come first or wherever their first row sits, without a
  heading. A row without a label shows the file name.
- **Each link serves exactly one file**, named `Title - Group - Label.ext`
  (`Baraye Arrangement - Hohe Stimme - Partitur.pdf`), so the same label in
  three groups does not collide in the reader's downloads folder and a saved
  file still says where it came from. The ASCII fallback name spells umlauts
  out (`Übe` becomes `Uebe`). Links expire and are signed like the single link
  always was; the file key in the URL is part of the signature.
- **Links are tap targets**: in the mail and on the page every file is its own
  row with a 44px-high link, separated by a hairline.
- **The download cap counts per file** for a list (nine files with a cap of
  three: each file can be fetched three times). A single file is capped per
  grant as before.
- **The mail template variable `{{ file_list }}`** carries the grouped list as
  HTML for templates authored in `goldnead/statamic-email-templates`;
  `{{ download_url }}` is then the overview page.
- **Published mail templates need republishing.** A delivery view you published
  into your site (`resources/views/vendor/lead-magnets/mail/delivery.blade.php`)
  or an email-templates template written before the list existed has no place
  for the files; the mail then carries only the link to the overview page.
  Republish the views (`php artisan vendor:publish --tag=lead-magnets-views
  --force`, after saving your own wording) or add `{{ file_list }}` to the
  template. When a multi-file delivery goes out through a template that does not
  list the files, a warning naming the fix is written to the log.
- A file keeps its key when the list is reordered or relabelled, so links already
  mailed keep serving the file they were sent for. Removing a file from the list
  retires its links (404).

**Existing single-file resources need no change and no data migration.** A
resource without a list is read as a list of one (`Resource::fileList()`), keeps
its direct link `/download/{grant}`, its mail without a list and its file name.
A list of one file behaves the same. The first file of a list is also kept in
`file_path`, so anything reading that column still finds a working file.

*Link* forwards the visitor to a URL you hold elsewhere. Both go through the
same signed route, so both are counted, capped and audited identically; the
listing names which of the two applies and what it points at.

### 2. Point a form at it

```html
<form method="POST" action="/!/lead-magnets/request">
    @csrf
    <input type="hidden" name="resource" value="warm_up">
    <input type="email" name="email" required>

    {{-- The honeypot. Hide it with CSS, never with `type="hidden"`. --}}
    <input type="text" name="website" tabindex="-1" autocomplete="off"
           style="position:absolute;left:-9999px">

    <input type="hidden" name="_redirect" value="/thanks">
    <button type="submit">Send it to me</button>
</form>
```

`POST` with an `Accept: application/json` header answers
`{"ok": true, "data": {"state": "pending"}}` instead of redirecting.

### 3. What happens next

With double opt-in on: a confirmation mail goes out, the grant is `pending`, and
the download link follows only once the address is confirmed. With it off: the
delivery mail goes out immediately.

### One confirmation for the file and the newsletter

By default a resource that names a mailing list subscribes the confirmed
address through marketing's own consent path, and if that list uses double
opt-in the reader gets a second confirmation mail. Switch on **Subscribe
through this confirmation** on the resource to make the one confirmation count
for both:

- It needs double opt-in on the resource, a mailing list and a **disclosure**:
  one sentence that says the file comes with the subscription. The Control
  Panel refuses the switch without all three. Show the same sentence on your
  form; the addon puts it in the confirmation mail and on the confirmation
  page, and in email-templates templates as `{{ list_consent_text }}`.
- When the confirmation mail goes out, the grant keeps a copy of the sentence
  (`meta.list_consent`). That copy, not the resource's current text, is what
  the consent record carries.
- **Opening the confirmation link does not confirm.** Mail scanners fetch every
  link, and a scanner may not subscribe anybody. For a coupled grant
  `GET /confirm/{token}` shows the disclosure and a button; the button's
  `POST` confirms. Uncoupled resources still confirm on `GET`.
- On the button press the file is delivered and marketing's `subscribe()` is
  called with `skip_confirmation` and `meta.consent` = method, list, wording,
  source (`lead-magnets:<handle>`), `requested_at`, `confirmed_at`.
- An editor reinstating a pending grant is not the reader's consent: the list
  then asks for its own confirmation. Switching coupling off stops mails
  already sent from confirming into the list.
- Unsubscribing does not touch the grant. The file stays downloadable.

### Mail templates per resource

*Confirmation template* and *Delivery template* on a resource name an
email-templates slug for that resource's mails (for example a welcome text per
freebie, with `{{ file_list }}` for the files). Empty uses
`lead-magnets.mail.confirmation_template` / `delivery_template`.

### From your own code

```php
use Goldnead\LeadMagnets\Facades\LeadMagnets;

$resource = LeadMagnets::resource('warm_up');

$grant = LeadMagnets::request($resource, 'reader@example.com', ['source' => 'api']);

$grant->state();            // Goldnead\Entitlements\Enums\EntitlementState
$grant->isRedeemable();     // the one question the download gate asks
LeadMagnets::downloadUrl($grant);

// Access questions go to entitlements, which answers them for every addon on
// the platform. There is deliberately no second facade over the same data.
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\LeadMagnets\Support\LeadMagnetSubject;

Entitlements::allows(LeadMagnetSubject::for('reader@example.com'), 'warm_up');
```

### Listening to events

```php
use Goldnead\LeadMagnets\Events\ResourceConfirmed;

Event::listen(ResourceConfirmed::class, function (ResourceConfirmed $event) {
    $event->grant->email;
    $event->payload();      // integration-shaped array, no secrets in it
});
```

---

## Routes

| Method | URL | Name |
|---|---|---|
| POST | `/!/lead-magnets/request` | `lead-magnets.request` |
| GET | `/!/lead-magnets/confirm/{token}` | `lead-magnets.confirm` (confirms; for a coupled grant shows the button instead) |
| POST | `/!/lead-magnets/confirm/{token}` | `lead-magnets.confirm.store` (the button) |
| GET | `/!/lead-magnets/download/{grant}` | `lead-magnets.download` (signed; the file, or the overview page for a list) |
| GET | `/!/lead-magnets/download/{grant}/{file}` | `lead-magnets.download.file` (signed; one file of a list) |

The prefix is configurable under `lead-magnets.routes.prefix`.

---

## Grant state lives in `goldnead/statamic-entitlements`

Version 1.x carried its own four-state lifecycle — `pending`, `active`,
`revoked`, `expired` — because the platform's entitlements package did not exist
yet. It does now, and 2.0 gives the state back.

### The six states, and which of them this addon writes

Entitlements has six. This addon **writes three** and **reads all six**.

| State | Written by lead-magnets | What it means here |
|---|---|---|
| `pending` | yes | a request is parked, waiting for the double opt-in |
| `active` | yes | the address is proven and the file may be fetched |
| `revoked` | yes | an editor withdrew access, with a recorded reason |
| `expired` | **no** | derived from `expires_at` by the resolver, never stored |
| `scheduled` | **no** | a start date in the future; grants nothing yet |
| `grace_period` | **no** | past the expiry, still allowed |

`expired` is not written by anybody, and that is a fix rather than an omission.
In 1.x it was a column somebody had to set — a request, a download attempt, the
hourly sweep — so a grant could sit past its date still saying `active` until
something noticed. The resolver reads the clock, so there is nothing to sweep and
nothing that can be stale. The sweep command survives with a much smaller job:
clearing confirmation tokens whose window has closed.

`scheduled` and `grace_period` have no writer here either, because nothing in a
lead-magnet flow produces them. They are read all the same, because an operator
can produce both from the entitlements Control Panel, and a download gate that
did not understand them would be wrong in both directions — serving a grant that
has not started, refusing one inside its grace period. Both are covered by tests.

### What crossed over and what did not

Only the access state. Signed links, the download cap, the audit rows, the
confirmation secret and both mails stayed here. Entitlements sends nothing at
all, by design: it decides access and announces it, and the delivery mail hangs
off `EntitlementGranted` in `src/Listeners/DeliverConfirmedResource.php`.

### How a grant appears in entitlements

| Column | Value |
|---|---|
| `subject_type` | `lead-magnet-contact` (configurable) |
| `subject_id` | SHA-256 of the normalised address |
| `product_slug` | the resource handle |
| `source` | `lead_magnet` (configurable) |
| `source_ref` | the access period number, starting at `1` |

The address is hashed rather than stored. `subject_id` is 64 characters and an
email may be 254, so storing it raw would truncate — and two addresses sharing a
long prefix would then collide on an index that decides access. The readable
list is this addon's own screen, which has the address; the entitlements listing
shows an opaque key for these rows.

`source_ref` counts access periods rather than being empty. A reader whose year
of access ran out and who asks again gets a **second** entitlement, not a rewrite
of the first: the expired row is a true record of a period that happened, and
entitlements answers over all of a subject's grants as an OR, so a second row is
exactly the shape it expects.

### Upgrading from 1.x

Two steps, in this order, because the second migration refuses to destroy state
that has not been carried across yet:

```bash
php artisan migrate                              # adds the new columns, then aborts
php artisan lead-magnets:migrate-grants --dry-run
php artisan lead-magnets:migrate-grants
php artisan migrate                              # drops the legacy columns
```

The command is idempotent, brand-aware and mails nobody: historical rows are
written straight to their final state, so `EntitlementGranted` carries no
previous state and the delivery listener stays quiet. A fresh install never sees
any of this — there are no rows, and both migrations run inside one `migrate`.

Entitlements' own `entitlements:announce` fires `EntitlementExpired` for grants
whose window has closed. Scheduling it is the host application's job: it is
shared by every consumer of the package, not owned by this addon.

---

## Security model

The download route carries `signed` middleware. The signature covers the whole
URL including its expiry, so an expired link, a link whose grant id was edited
and a link with an added parameter are all rejected with 403 before the
controller runs.

The signature proves the link was *issued*. Whether the access still *stands* is
a separate question the controller asks: a revoked grant holds links that verify
perfectly and must not serve. Both are tested.

Confirmation tokens are minted with `random_bytes(32)`, stored only as a
SHA-256 hash, and cleared the moment they are used. A leaked database row is not
a working confirmation link.

### Where the files live

An uploaded resource goes into the addon's own asset container, `lead_magnets`,
on the addon's own disk, `lead-magnets`. That disk is defined by the addon —
`storage/app/lead-magnets`, with no `url`, no `serve` and no public
visibility — so it sits outside the document root and Laravel registers no
route against it. The signed download route is the only way to the file.

This is the reason the addon does not simply use a container that is already
there. Statamic's default asset container is on `public/assets`: a URL, public
visibility, and files the web server hands over before Laravel sees the request.
A resource put there is a public download whatever the grant says, and the
`assets` fieldtype would have picked exactly that container by default.

If you point `assets.disk` at a disk of your own that turns out to be
web-accessible — a `url`, public visibility, or a root inside `public/` — the
resource form says so in red rather than letting it pass. `AssetContainer::private()`
does not catch all three cases, so the addon asks the wider question itself.

Both halves of that claim are tested: the file is refused over every public
address it could plausibly have, and delivered over the signed route in the same
test.

---

## Multi-brand

Under `goldnead/statamic-brand-context` multi-brand mode, resources, grants and
download rows are brand-scoped. The three public routes carry no session, so the
brand is derived from the value the visitor already holds — the resource handle,
the confirmation token, the grant id. Each of those addresses exactly one record
across all brands, which is what makes that derivation safe.

That is also why **resource handles are unique globally, not per brand**. Two
brands cannot both own a resource called `warm_up`.

---

## Configuration

See `config/lead-magnets.php`. The settings worth knowing:

| Key | Default | Meaning |
|---|---|---|
| `delivery.link_ttl` | `10080` (7 days) | Signed-link lifetime in minutes |
| `delivery.max_downloads` | `null` | Redemptions per grant; `null` = uncapped |
| `delivery.grant_ttl_days` | `null` | Access lifetime; `null` = forever |
| `requests.confirmation_ttl_hours` | `72` | How long a confirmation link lives |
| `requests.honeypot` | `website` | Field name a bot fills and a human never does |
| `requests.throttle` | `10,1` | Requests per minute per client |
| `entitlements.source` | `lead_magnet` | Marks an entitlement as this addon's |
| `entitlements.subject_type` | `lead-magnet-contact` | Morph type of a lead-magnet contact |
| `assets.container` | `lead_magnets` | The asset container uploads go into |
| `assets.disk` | `lead-magnets` | Its disk. Defined by the addon unless the host already defines one under that name — and it must not be web-accessible |
| `integrations.*` | `true` | Turn an installed sibling's bridge off |

Most of these can be overridden per resource in the Control Panel. The two
`entitlements` keys cannot, and are install-time settings: both are part of the
entitlements unique key, so changing either after grants exist orphans every row
written under the old value.

`delivery.grant_ttl_days` and `requests.confirmation_ttl_hours` look alike and
are not. The first is how long access lasts once the address is proven; the
second is how long the visitor has to prove it. They are stored in two different
columns on two different rows for exactly that reason.

---

## Testing

```bash
composer test                                  # SQLite
vendor/bin/pest --configuration=phpunit.mysql.xml   # MySQL
composer lint
composer analyse
```

The MySQL leg is not optional in CI. SQLite has no InnoDB key limit and no
utf8mb4 byte arithmetic, and this addon carries a three-column unique that ends
in an email address.

---

## Licence

Commercial license. See [LICENSE](LICENSE).
