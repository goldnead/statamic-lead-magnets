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
| GET | `/!/lead-magnets/confirm/{token}` | `lead-magnets.confirm` |
| GET | `/!/lead-magnets/download/{grant}` | `lead-magnets.download` (signed) |

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

MIT. See [LICENSE](LICENSE).
