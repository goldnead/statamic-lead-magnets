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
- **Grant state** — `pending → active`, plus `revoked` and `expired`, one grant
  per address per resource.
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
| Hard dependency | `goldnead/statamic-brand-context` |

Everything else is optional. **The addon is fully functional with no sibling
addon installed**: it sends its own confirmation mail, holds its own grant
state and serves its own downloads. The test suite runs with none of them
present, which is what makes that a claim rather than a hope.

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

$grant->state;              // 'pending' or 'active'
LeadMagnets::downloadUrl($grant);
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

## Grant state — a documented deviation from the platform architecture

The platform's target architecture (`SYSTEM/statamic-platform-addon-ecosystem.md`
§4.4) puts grants in a package of their own, `goldnead/statamic-entitlements`,
and has every consumer read them from there. **That package does not exist.** It
is deferred until a second consumer justifies designing the shared abstraction.

So this addon carries its own grant state — `pending`, `active`, `revoked`,
`expired`, per contact and resource, with a delivery and download audit. That is
a deliberate deviation, taken with open eyes:

- **The cost.** When entitlements arrives there will be two grant models and a
  migration between them.
- **The benefit.** The addon exists, and it supplies exactly the second consumer
  entitlements is waiting for.
- **The alternative** — build entitlements first — inverts the order and designs
  the abstraction before the second real use case, which §10 of the same document
  explicitly advises against.

If and when entitlements ships, the connection is an optional bridge, not a
Composer requirement. Nothing in this package's public API names an entitlement,
so that migration is an internal one.

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
| `integrations.*` | `true` | Turn an installed sibling's bridge off |

Each of these can be overridden per resource in the Control Panel.

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
