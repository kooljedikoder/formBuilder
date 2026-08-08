# KilliSearch Ultra — Free / Standard / Ultra

Three license tiers. A fresh install starts on **Free**; entering a valid license key
(`admin/connections.php`) unlocks **Standard** or **Ultra** for the whole installation.
See `KILI_BUILD_STATUS.md` for how the entitlement system works internally.

Four pillars make up the product: **Search** (find it), **Conversational** (talk about
it), **Memory** (remember what's been asked), and **CRUD** (own it — create, edit,
delete, export and back up your own data instead of only reading it).

## Included in every plan

These aren't feature-gated in code — every installation gets them regardless of license.

- Core search engine: exact/partial matching, fuzzy typo correction, phonetic (Soundex) matching, ranking
- Location, taxonomy (sector/category/subcategory) and source-provenance-aware results
- Rule-based conversational replies — no AI/LLM call, deterministic intent detection
- Chat UX: dark/light mode, voice input, message reactions, delivery ticks, animations
- Swappable local data sources (JSON datasets) via the Data Source Engine

These stay free on every tier deliberately: they cost nothing extra to run (client-side
or negligible server load) and the tiers instead differentiate on business value —
capturing/remembering visitors (Standard) and owning your own data infrastructure and
brand (Ultra) — rather than on chat polish.

## Comparison

| | **Free** | **Standard** | **Ultra** |
|---|:---:|:---:|:---:|
| Search, location, taxonomy, source engines | ✅ | ✅ | ✅ |
| Conversational chat UI (voice, dark mode, reactions) | ✅ | ✅ | ✅ |
| Conversational Enquiry / Support form | — | ✅ | ✅ |
| Memory engine (instant answers to repeated questions) | — | ✅ | ✅ |
| File attachments in chat | — | ✅ | ✅ |
| Full record CRUD (create/edit/delete your data) | — | ✅ | ✅ |
| Data export (JSON / CSV) and one-click backups | — | ✅ | ✅ |
| CSV / JSON data import with auto field-mapping | — | — | ✅ |
| Live database connections — cached snapshot or real-time live query | — | — | ✅ |
| Multiple, swappable data sources | — | — | ✅ |
| Remove "Powered by Killi" branding (white-label) | — | — | ✅ |

## Feature keys (for reference)

Each row above maps to a feature key checked in code (`kili_has_feature()` /
`kili_require_feature_json()`), defined in `config/packages.json`:

| Feature key | Free | Standard | Ultra |
|---|:---:|:---:|:---:|
| `search` | ✅ | ✅ | ✅ |
| `forms` | | ✅ | ✅ |
| `memory` | | ✅ | ✅ |
| `attachments` | | ✅ | ✅ |
| `crud` | | ✅ | ✅ |
| `import` | | | ✅ |
| `db_connections` | | | ✅ |
| `multi_source` | | | ✅ |
| `white_label` | | | ✅ |

`attachments` is enforced both in the UI (the attach button doesn't render without it)
and server-side in `api/upload.php` — a Free/Standard-without-license install can't
stash files on disk just because someone bypasses the button. `white_label` controls a
"Powered by Killi" line rendered under the search bar in `portal/index.php`, present on
Free and Standard, gone on Ultra.

`crud` unlocks `admin/records.php` (browse/search/create/edit/delete records in any
configured data source — except a live-query source, which stays read-only, see
below), `admin/backup.php` (create/list/download/delete/restore a zip of `data/` +
`config/` on demand), `admin/faq.php` (promote logged questions into curated FAQ
answers), and export links (`api/export.php?format=json|csv`). All of this is
admin-only — you managing your own data, not a customer-facing capability.

`db_connections` unlocks connecting a MySQL/PostgreSQL database in
`admin/connections.php` at all (test/preview/publish), in either of two modes: publish
as a **cached snapshot** (rows copied into a JSON file once, editable via `crud`
afterward — the original mode), or publish as a **live query** (search hits the
database on every request via `core/DbAdapter.php`, so it reflects rows
added/edited/removed in the table with no re-publishing — but read-only, since safely
writing back to an arbitrary table schema is a separate, larger feature than reading
from one).

## Activating a license

1. Go to `admin/connections.php` (mandatory admin login/setup on first visit).
2. Under **Licensing / packages → Activate a license key**, enter the key.
3. The matching package unlocks for the whole installation immediately.

Demo keys for testing:

| Key | Unlocks |
|---|---|
| `KILLI-STANDARD-DEMO-0001` | Standard |
| `KILLI-ULTRA-DEMO-0001` | Ultra |

A real deployment would replace `data/licenses.json` with actual issued keys — see
`core/LicenseManager.php`.
