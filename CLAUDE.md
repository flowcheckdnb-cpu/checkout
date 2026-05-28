# Onsite Test (Magento 2.4.8-p4 + Hyvä)

Docker is running, `auth.json` has real keys, `/etc/hosts` has the domain, mkcert CA is trusted.

## Stack

- Magento Open Source 2.4.8-p4 + sample data
- Hyvä Default theme — no Venta, no Magebit private modules, no Hyvä Checkout (paid)
- Ubuntu 24.04 monolith app container, rootless `ubuntu` user (UID 1000)
- Nginx + PHP 8.3 FPM (unix socket) + supervisord (cron + sshd toggleable, off by default)
- MariaDB 11.4 LTS, Valkey 8.1.7 split (cache LFU + sessions LRU/AOF)
- OpenSearch 2.19.5 (analysis-phonetic + analysis-icu)
- Varnish 7.7.3 — Magento FPC, sits between Traefik and app
- Mailpit — catches all outbound mail
- Traefik v3.7 — `:80` redirects to `:443`, mkcert-signed TLS
- Composer 2.9.7, magerun2 9.4.0, supercronic 0.2.45

## Request flow

```
Browser → Traefik :443 (mkcert TLS) → Varnish :80 → nginx :8080 → php-fpm (unix socket)
                :80 → 308 → :443
```

`./dev.sh up` bootstraps mkcert + the local CA on first run (password prompt once). Magento is wired to Varnish FPC: `system/full_page_cache/caching_application=2`, PURGE goes to `varnish:80`. `X-Magento-Cache-Debug: HIT/MISS` response header confirms caching. VCL bypasses `/customer`, `/checkout`, `/health_check.php`. SMTP routes to Mailpit (`system/smtp/host=mailpit:1025`).

## URLs

- Storefront: <https://onsite-test.docker>
- Admin: <https://onsite-test.docker/admin>
- Mailpit: <https://mail.onsite-test.docker>
- Traefik dashboard: <http://localhost:8090>

## Layout

```
.docker/
├── app/         # App container — Dockerfile, nginx/php/supervisor/cron configs, entrypoint
├── opensearch/  # OpenSearch image with analysis-phonetic + analysis-icu
├── varnish/     # default.vcl (mounted into stock varnish:7.7.3-alpine, no custom build)
├── traefik/     # dynamic.yml (cert reference) + ssl/ (mkcert-generated, gitignored)
└── bin/         # mkcert binary (downloaded by dev.sh, gitignored)
.claude/
├── settings.json  # Light deny rules for catastrophic ops
└── skills/        # Hyvä's official AI skills (12 skills, vendored)
.env             # DOMAIN, DB creds, FPM tuning, optional Xdebug
phpstan.neon     # PHPStan level 9 + strict + deprecation rules + bitExpert magic-method support
phpcs.xml.dist   # PHPCS: Magento2 + PSR-12 + Slevomat (style + conventions)
auth.json        # Magento Marketplace + Hyvä Private Packagist creds (committed intentionally as exception)
bootstrap.sh     # Idempotent: composer install → setup:install → sample data → Hyvä → Tailwind
compose.yaml     # Self-contained: Traefik, app, mariadb, valkey ×2, opensearch, varnish, mailpit
dev.sh           # up / down / restart / build / status / shell / logs / bootstrap / cert / reset / nuke
composer.json    # Magento 2.4.8-p4 + sample data (Hyvä packages added by bootstrap.sh)
```

## AI skills

Hyvä's [official AI skills](https://github.com/hyva-themes/hyva-ai-tools) (OSL-3.0) ship under `.claude/skills/hyva-*/` — covers child theme creation, module scaffolding, Alpine + UI components, CMS components/custom-fields, Tailwind compilation, image rendering, Playwright tests. Refresh from upstream when needed:

```bash
curl -fsSL https://raw.githubusercontent.com/hyva-themes/hyva-ai-tools/refs/heads/main/install.sh | sh -s claude
```

## Static analysis

Strict by design — better to start tight and adjust as we hit real cases than to start lenient and never tighten. Two analyzers, no overlap, no competing tools.

| Tool | Role | Config |
|---|---|---|
| **PHPStan** + strict + deprecation + bitExpert | Logic, types, bugs, deprecations | `phpstan.neon` (level 9, scope `app/code`) |
| **PHPCS** + Magento2 + PSR-12 + Slevomat | Style, conventions | `phpcs.xml.dist` |

Commands (run from host, executed inside the container):

```bash
./dev.sh stan      # PHPStan analyse
./dev.sh sniff     # PHPCS report
./dev.sh fix       # phpcbf auto-fix (style only)
./dev.sh check     # both analyzers (what CI will gate on once added)
```

**Adding to `ignoreErrors:` (PHPStan)**: must include a `path:` (file or glob) AND a `# why:` comment explaining the cause. Broad message-only patterns are forbidden — they hide future bugs. If it's "undefined method" on a DataObject subclass, fix by adding `@method` PHPDoc to the class instead. If it's a third-party library quirk, narrow ignore is fine.

**Adding to `phpcs.xml.dist` exclusions**: prefer fixing the code. Real exceptions need an inline XML comment explaining why.

**Auto-check on edit**: `.claude/hooks/check-php.sh` runs PHPCS + PHPStan on every Edit/Write to `*.php`/`*.phtml` files under `app/code/` or `app/design/frontend/`. Other file types are silently skipped. If checks fail, the edit is blocked and the violations come back into the conversation. Container must be running for the hook to fire.

## Working with this project

- **Fresh checkout**: `./dev.sh up && ./dev.sh bootstrap` (~20 min first time)
- **Container shell**: `./dev.sh shell` — bash as `ubuntu`, cwd `/app`
- **Run `bin/magento` / `composer`**: from inside the shell, never on the host
- **Reset Magento** (DB + indexes + caches, keeps vendor): `./dev.sh reset` (~3-5 min)
- **Magento mode**: `developer` (no need to switch for typical tasks)
- **Cron is OFF by default**: flip `ENABLE_CRON: "true"` in `compose.yaml` for queue consumers / scheduled jobs
- **Bootstrap is idempotent**: detects already-installed components and skips them — safe to re-run
- **Tail PHP errors**: `./dev.sh shell` → `tail -f var/log/system.log var/log/exception.log`

## Sample data quirks

- **The `home` CMS page is empty** (0 bytes content) — Magento ships the homepage record blank, expecting merchants to fill it in. The page renders with header/footer/Hyvä layout but no body content. Edit in admin → Content → Pages → Home, or seed via a data patch.
- **`/what-is-new.html` is a category, not a CMS page** — maps to `catalog/category/view/id/38` from sample data's url_rewrite. Renders empty because that category has no assigned products. Same applies to any sample category with no products.
- Other sample CMS pages (`about-us`, `customer-service`, `enable-cookies`, `privacy-policy-cookie-restriction-mode`, `no-route`) are populated.

## Heads up

- **`./dev.sh nuke`** is destructive — deletes containers, volumes, and the app image. Use `./dev.sh reset` for a clean Magento DB without losing the build.
