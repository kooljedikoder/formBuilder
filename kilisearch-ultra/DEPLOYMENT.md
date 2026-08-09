# Deploying KilliSearch Ultra

Plain PHP 8, vanilla JS, no build step, no required framework, no required
database. This covers getting `kilisearch-ultra/` live on a real server.

## 1. Requirements

- **PHP 8.0+**
- **`gd` extension** — `portal/icon.php` draws the PWA app icon on the fly;
  without it the icon (and therefore installability) breaks
- **`pdo_mysql` and/or `pdo_pgsql`** — only needed if you'll use the Ultra
  database-connection features (cached snapshot or live query). Pure JSON
  mode needs neither.
- **HTTPS** — required for the service worker to register at all. Browsers
  refuse `serviceWorker.register()` over plain HTTP in production (only
  `localhost` is exempted). No HTTPS = no install prompt, no offline
  fallback.
- **Write access** for the web server's user on:
  - `kilisearch-ultra/data/` — content, admin accounts, query log, audit log
  - `kilisearch-ultra/config/` — packages, branding, data source config

## 2. Get the code onto the server

```bash
git clone https://github.com/kooljedikoder/formBuilder.git
cd formBuilder
git checkout claude/kilisearch-ultra-build-qsuo20   # or master once merged
```

Point the web server's document root at `kilisearch-ultra/` (or a
subdirectory/subdomain if it's sharing a box with something else — every
path in the app is relative-safe).

## 3. Hosting shapes

### Shared / cPanel hosting

1. Upload the `kilisearch-ultra/` contents via git or FTP.
2. Set the document root (or a subdomain) to that folder.
3. Make sure `data/` and `config/` are writable by the PHP process:
   ```bash
   chmod -R 775 data config
   ```
4. No `.htaccess` rewriting is required — every route is a real `.php`
   file (`/admin/connections.php`, `/portal/index.php`, etc.), not a
   pretty-URL front controller.

### VPS — nginx + php-fpm

```nginx
server {
    listen 443 ssl;
    server_name your-domain.example;

    root /var/www/killi/kilisearch-ultra;
    index index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location / {
        try_files $uri $uri/ =404;
    }

    # Never let a browser cache PWA files past what the app itself controls
    location ~ ^/portal/(manifest\.php|sw\.js)$ {
        add_header Cache-Control "no-cache";
    }
}
```

### VPS — Apache + mod_php

No special `.htaccess` needed; a standard vhost pointing `DocumentRoot` at
`kilisearch-ultra/` with `AllowOverride None` works, since there's no
rewriting to do.

### Docker

```dockerfile
FROM php:8.2-apache
RUN docker-php-ext-install gd pdo_mysql pdo_pgsql
COPY kilisearch-ultra/ /var/www/html/
RUN chown -R www-data:www-data /var/www/html/data /var/www/html/config
```

Mount `data/` and `config/` as volumes so admin changes, uploaded content,
and license activation survive container restarts/redeploys:

```bash
docker run -d \
  -p 443:443 \
  -v killi-data:/var/www/html/data \
  -v killi-config:/var/www/html/config \
  your-image
```

## 4. First-visit setup

Once it's reachable, open `/admin/setup.php` first. The guided wizard:

1. **License activation** — enter a key to unlock Standard/Ultra, or skip
   to stay on Free.
2. **App password** — optionally require a shared password before anyone
   can use the search/chat app. Skippable.
3. **Completion** — creates your first admin account (always `owner`
   role) and marks setup complete.

Visiting `/admin/setup.php` again afterward redirects editors straight
past it — only an owner can re-enter it.

## 5. Go-live checklist

- [ ] HTTPS actually terminates correctly — confirm `/portal/manifest.php`
      loads and the install icon appears in a real browser
- [ ] `data/` and `config/` are writable by the PHP process, nothing else
      world-writable
- [ ] If using a real database: add credentials from the admin
      **Database connections** card, then **Test** → **Preview** →
      **Publish**
- [ ] Take a backup immediately after setup (`/admin/backup.php`) as a
      known-good starting point
- [ ] Decide on branding (product name, colors, tagline) before sharing
      the install link — the PWA icon/splash bake in whatever's set at
      install time

## 6. Updating a live deployment

`data/` and `config/` are tracked in git as **seed** content (the demo
records, default packages.json, etc.), not gitignored — so a plain
`git pull` on a live deployment can genuinely overwrite real admin
accounts, licensing state, or content if a later commit happens to touch
those same files. Two safe options:

**A — stop git from touching runtime files after the first deploy**
(recommended for anything with real data in it):
```bash
git update-index --skip-worktree kilisearch-ultra/data/*.json kilisearch-ultra/config/*.json
git pull origin claude/kilisearch-ultra-build-qsuo20   # or master
```
`--skip-worktree` tells git to leave those files alone on future pulls
while still tracking everything else normally. If you genuinely need to
pull an upstream change to one of those files later, `git update-index
--no-skip-worktree <file>` first, pull, then re-apply `--skip-worktree`.

**B — back up, pull, restore** if you'd rather not touch git's index:
```bash
php admin/backup.php   # or trigger a backup from the admin UI first
git pull origin claude/kilisearch-ultra-build-qsuo20
# if the pull touched data/ or config/, restore the backup you just took
```

Either way, take a backup via `/admin/backup.php` before every deploy —
it's the fastest way back to a known-good state if something did get
overwritten.

## 7. What deployment does *not* require

- No `composer install` — zero third-party PHP dependencies
- No `npm install` / build step — the JS/CSS are shipped as-is
- No database migrations — JSON mode needs nothing; DB mode reads/writes
  your existing schema directly, it doesn't create one
