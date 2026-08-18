# Orange Cafe

Restaurant management for a single venue: a QR-code customer menu, waiter and
cashier order-taking, a kitchen display, thermal ticket/receipt printing, table
and waiter-call management, and an admin back office — bilingual (Arabic/English)
with database-driven languages.

Ships two ways from the same codebase:

- **as an ordinary Laravel app**, behind nginx/Apache, and
- **as a single Windows `.exe`** that one machine in the cafe runs, with every
  other device — waiter tablets, kitchen screen, cashier, customers' phones —
  reaching it through a browser over the local network, installing nothing.

The desktop build is the primary delivery target. See
**[docs/desktop-build.md](docs/desktop-build.md)** for how it is packaged, why
it runs on SQLite, and what a fresh install does on first launch.

---

## Requirements

- PHP 8.2+ with `gd`, `intl`, `mbstring`, `zip`, `bcmath`
- Node 18+
- `pdftoppm` (poppler) — required for thermal printing; `build/fetch-poppler.ps1`
  stages a bundled copy on Windows

## Local development

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan app:first-run-setup     # migrate + seed languages/roles + admin account
composer dev                        # serve + queue + reverb + vite
```

`app:first-run-setup` prints the generated admin credentials and also writes
them to `storage/first-run-credentials.txt`.

To run the desktop shell instead of the web server:

```bash
composer native:dev
```

## Tests

```bash
php artisan test
```

The suite runs against an in-memory SQLite database (`phpunit.xml`), so it never
touches the working database.

## Architecture notes worth knowing before changing things

| Area | Note |
|---|---|
| **URLs** | Every page lives under a `/{locale}/` prefix, produced per request by `mcamara/laravel-localization`. The native control panel and `/storage/{path}` deliberately sit outside it. |
| **Languages** | Driven by the `languages` table, not config. `LanguageRegistry` pushes it into Astrotomic, mcamara and `app.locale` at boot, and is the only place that reads it. |
| **Printing** | Tickets are rendered as *bitmaps* (mPDF → `pdftoppm` → ESC/POS raster), because cheap thermal printers cannot shape Arabic in their built-in fonts. See `EscPosImageRenderer`. |
| **Live updates** | Laravel Reverb. The browser dials `window.location.hostname` (`resources/js/echo.js`), so tablets connect correctly without a per-install rebuild. |
| **Table QR codes** | Built through `PublicUrlResolver`, not the current request host — a sign generated on the server machine must not encode `localhost`. |
| **Uploaded images** | Served by `StorageFileController` when no `public/storage` symlink is available, which is always the case in the packaged app. |
