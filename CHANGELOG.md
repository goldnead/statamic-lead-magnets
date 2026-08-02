# Changelog

All notable changes to `goldnead/statamic-lead-magnets` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
